<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\GetSiteLog;
use App\Mcp\Tools\GetWorkerLog;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListSites;
use App\Mcp\Tools\ListWorkers;
use App\Models\AuditEntry;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ForgeClient::flushCache();
    Cache::flush();

    $worker = fn (int $id, string $dir, string $cmd) => ['id' => (string) $id, 'type' => 'background-processes', 'attributes' => ['command' => $cmd, 'user' => 'forge', 'directory' => $dir, 'processes' => 2, 'status' => 'running']];

    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/logs/application' => Http::response(['data' => ['attributes' => ['content' => "line1\nAPP_KEY=base64:supersecretvalue123456\nline3\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/logs/nginx-error' => Http::response(['data' => ['attributes' => ['content' => "e1\ne2\ne3\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/environment' => Http::response(['data' => ['attributes' => ['content' => "APP_KEY=base64:supersecretvalue123456\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/13/environment' => Http::response(['data' => ['attributes' => ['content' => "X=1\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1, 'root_directory' => '/home/forge/alpha.test'],
            ['id' => 13, 'name' => 'beta.test', 'server_id' => 1, 'root_directory' => '/home/forge/beta.test'],
        ])),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/5/log' => Http::response(['data' => ['attributes' => ['content' => "w1\nsecret=base64:supersecretvalue123456\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/6/log' => Http::response(['data' => ['attributes' => ['content' => "orphan log\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/5' => Http::response(['data' => $worker(5, '/home/forge/alpha.test/current', 'php artisan queue:work')]),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/6' => Http::response(['data' => $worker(6, '/opt/other', 'node app.js')]),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes*' => Http::response(['data' => [
            $worker(5, '/home/forge/alpha.test/current', 'php artisan queue:work'),
            $worker(6, '/opt/other', 'node app.js'),
            $worker(7, '/home/forge/beta.test', 'php artisan horizon'),
        ]]),
        'forge.laravel.com/api/orgs/acme/servers/1' => Http::response(['data' => ['id' => '1', 'type' => 'servers', 'attributes' => ['name' => 'web-1']]]),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [['id' => 1, 'name' => 'web-1']])),
        'forge.laravel.com/api/orgs/acme/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1],
        ])),
    ]);
});

function logUser(array $grants = []): array
{
    $user = mcpOwner(['acme']);
    $token = mcpToken($user, $grants);
    mcpActAs($user, $token);

    return [$user, $token];
}

$logsAllowed = [Capability::ViewLogs, ScopeType::Global, new Target, true];

it('get_site_log returns a scrubbed tail', function () use ($logsAllowed) {
    [$user] = logUser([$logsAllowed]);

    ForgeServer::actingAs($user)->tool(GetSiteLog::class, ['server_id' => 1, 'site_id' => 12, 'type' => 'application'])
        ->assertOk()
        ->assertSee(['line1', 'line3'])
        ->assertDontSee('supersecretvalue123456');
});

it('get_site_log honours lines', function () use ($logsAllowed) {
    [$user] = logUser([$logsAllowed]);

    ForgeServer::actingAs($user)->tool(GetSiteLog::class, ['server_id' => 1, 'site_id' => 12, 'type' => 'nginx-error', 'lines' => 1])
        ->assertOk()
        ->assertSee(['showing last 1 of 3', 'e3'])
        ->assertDontSee('e1');
});

it('invalid log type rejected', function () use ($logsAllowed) {
    [$user] = logUser([$logsAllowed]);

    ForgeServer::actingAs($user)->tool(GetSiteLog::class, ['server_id' => 1, 'site_id' => 12, 'type' => '../../etc/passwd'])
        ->assertHasErrors(['Invalid log type']);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'passwd'));
});

it('get_site_log is denied for a site with ViewLogs denied', function () {
    [$user] = logUser([[Capability::ViewLogs, ScopeType::Site, new Target('acme', 1, 12), false]]);

    ForgeServer::actingAs($user)->tool(GetSiteLog::class, ['server_id' => 1, 'site_id' => 12, 'type' => 'application'])
        ->assertHasErrors(['not permitted']);
});

it('list_workers lists workers with resolved sites', function () {
    [$user] = logUser();

    ForgeServer::actingAs($user)->tool(ListWorkers::class, ['server_id' => 1])
        ->assertOk()
        ->assertSee("5 running x2 forge php artisan queue:work site:12\n6 running x2 forge node app.js site:-\n7 running x2 forge php artisan horizon site:13");
});

it('list_workers filters by site_id', function () {
    [$user] = logUser();

    ForgeServer::actingAs($user)->tool(ListWorkers::class, ['server_id' => 1, 'site_id' => 13])
        ->assertOk()
        ->assertSee('horizon')
        ->assertDontSee(['queue:work', 'node app.js']);
});

it('list_workers omits view-denied workers', function () {
    [$user] = logUser([[Capability::View, ScopeType::Site, new Target('acme', 1, 12), false]]);

    ForgeServer::actingAs($user)->tool(ListWorkers::class, ['server_id' => 1])
        ->assertOk()
        ->assertSee(['node app.js', 'horizon'])
        ->assertDontSee('queue:work');
});

it('get_worker_log returns a scrubbed tail for a resolved worker', function () use ($logsAllowed) {
    [$user] = logUser([$logsAllowed]);

    ForgeServer::actingAs($user)->tool(GetWorkerLog::class, ['server_id' => 1, 'worker_id' => 5])
        ->assertOk()
        ->assertSee('w1')
        ->assertDontSee('supersecretvalue123456');
});

it('worker without a site uses server rules', function () {
    [$user] = logUser([
        [Capability::ViewLogs, ScopeType::Global, new Target, false],
        [Capability::ViewLogs, ScopeType::Site, new Target('acme', 1, 12), true],
    ]);

    // Site 12 allow grants its own worker...
    ForgeServer::actingAs($user)->tool(GetWorkerLog::class, ['server_id' => 1, 'worker_id' => 5])
        ->assertOk()->assertSee('w1');

    // ...but not the worker with no resolved site.
    ForgeServer::actingAs($user)->tool(GetWorkerLog::class, ['server_id' => 1, 'worker_id' => 6])
        ->assertHasErrors(['not permitted']);

    expect(AuditEntry::query()->latest('id')->first()->outcome)->toBe('denied');
});

it('get_worker_log rejects a server outside the organization', function () use ($logsAllowed) {
    [$user] = logUser([$logsAllowed]);

    ForgeServer::actingAs($user)->tool(GetWorkerLog::class, ['server_id' => 99, 'worker_id' => 5])
        ->assertHasErrors(['Server 99 is not in organization acme.']);
});

it('a list tool says nothing is visible when View is allowed somewhere but denied for every item', function () {
    [$user] = logUser([
        [Capability::View, ScopeType::Global, new Target, false],
        [Capability::View, ScopeType::Server, new Target('acme', 99), true],
    ]);

    ForgeServer::actingAs($user)->tool(ListServers::class)->assertOk()->assertSee('No servers visible to this access token.');
    ForgeServer::actingAs($user)->tool(ListSites::class)->assertOk()->assertSee('No sites visible to this access token.');
});

it('list tools are denied when View is denied everywhere', function () {
    [$user] = logUser([[Capability::View, ScopeType::Global, new Target, false]]);

    foreach ([[ListServers::class, []], [ListSites::class, []], [ListWorkers::class, ['server_id' => 1]]] as [$tool, $args]) {
        ForgeServer::actingAs($user)->tool($tool, $args)->assertHasErrors();
    }

    // The tool is hidden, so nothing reaches Forge.
    Http::assertNothingSent();
});
