<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\CreateWorker;
use App\Mcp\Tools\DeleteWorker;
use App\Mcp\Tools\RestartWorker;
use App\Mcp\Tools\StartWorker;
use App\Mcp\Tools\StopWorker;
use App\Mcp\Tools\UpdateWorker;
use App\Models\AuditEntry;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ForgeClient::flushCache();
    Cache::flush();

    $worker = fn (int $id, string $dir) => ['id' => (string) $id, 'type' => 'background-processes', 'attributes' => ['command' => 'php artisan queue:work', 'user' => 'forge', 'directory' => $dir, 'processes' => 1, 'status' => 'running']];

    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/5/actions' => Http::response('', 202),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/5' => Http::response(['data' => $worker(5, '/home/forge/alpha.test/current')]),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/9' => Http::response(['data' => $worker(9, '/home/forge')]),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/6' => Http::response(['data' => $worker(6, '/opt/other')]),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes/7' => Http::response(['data' => $worker(7, '/home/forge/beta.test')]),
        'forge.laravel.com/api/orgs/acme/servers/1/background-processes' => Http::response(['data' => ['id' => '20', 'type' => 'background-processes', 'attributes' => ['command' => 'php artisan queue:work']]], 201),
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1, 'root_directory' => '/home/forge/alpha.test'],
            ['id' => 13, 'name' => 'beta.test', 'server_id' => 1, 'root_directory' => '/home/forge/beta.test'],
        ])),
        'forge.laravel.com/api/orgs/acme/sites/12' => Http::response(['data' => ['id' => '12', 'type' => 'sites', 'attributes' => ['name' => 'alpha.test']]]),
        'forge.laravel.com/api/orgs/acme/servers/1' => Http::response(['data' => ['id' => '1', 'type' => 'servers', 'attributes' => ['name' => 'web-1']]]),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [['id' => 1, 'name' => 'web-1']])),
    ]);
});

function wrkUser(array $grants): object
{
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, $grants));

    return $user;
}

function wrkAssertNoWrite(int $workerId): void
{
    Http::assertNotSent(fn ($r) => str_contains($r->url(), "/background-processes/{$workerId}") && $r->method() !== 'GET');
}

function wrkSent(string $method, string $suffix): ?array
{
    $found = null;
    Http::assertSent(function ($r) use ($method, $suffix, &$found) {
        if ($r->method() === $method && str_ends_with($r->url(), $suffix)) {
            $found = $r->data();
        }

        return true;
    });

    return $found;
}

$manage = [Capability::ManageWorkers, ScopeType::Global, new Target, true];
$configure = [Capability::ConfigureWorkers, ScopeType::Global, new Target, true];

it('restart, stop and start queue the matching action', function (string $tool, string $action, string $word) use ($manage) {
    $user = wrkUser([$manage]);

    ForgeServer::actingAs($user)->tool($tool, ['server_id' => 1, 'worker_id' => 5])
        ->assertOk()->assertSee("{$word} queued for worker 5 on server web-1.");

    expect(wrkSent('POST', '/background-processes/5/actions'))->toBe(['action' => $action]);
    expect(AuditEntry::query()->latest('id')->first()->summary)->toBe("{$word} worker 5 on server 1.");
})->with([
    [RestartWorker::class, 'restart', 'Restart'],
    [StopWorker::class, 'stop', 'Stop'],
    [StartWorker::class, 'start', 'Start'],
]);

it('manage_workers on site A doesn\'t allow a worker on site B', function () {
    $user = wrkUser([
        [Capability::ManageWorkers, ScopeType::Global, new Target, false],
        [Capability::ManageWorkers, ScopeType::Site, new Target('acme', 1, 12), true],
    ]);

    ForgeServer::actingAs($user)->tool(RestartWorker::class, ['server_id' => 1, 'worker_id' => 5])->assertOk();
    ForgeServer::actingAs($user)->tool(RestartWorker::class, ['server_id' => 1, 'worker_id' => 7])
        ->assertHasErrors(['not permitted']);

    expect(AuditEntry::query()->latest('id')->first()->outcome)->toBe('denied');
    wrkAssertNoWrite(7);
});

it('create_worker posts defaults and the name defaults to the command', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'php artisan queue:work'])
        ->assertOk()->assertSee('Worker 20 created on server web-1.')->assertDontSee('as root');

    expect(wrkSent('POST', '/servers/1/background-processes'))
        ->toBe(['name' => 'php artisan queue:work', 'command' => 'php artisan queue:work', 'user' => 'forge', 'processes' => 1]);
});

it('create_worker with a site sends site_id and leaves directory unset', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'php artisan horizon', 'site_id' => 12, 'name' => 'horizon', 'processes' => 3])
        ->assertOk()->assertSee('Worker 20 created on server web-1 for site 12.');

    expect(wrkSent('POST', '/servers/1/background-processes'))
        ->toBe(['name' => 'horizon', 'command' => 'php artisan horizon', 'user' => 'forge', 'processes' => 3, 'site_id' => 12]);
});

it('create_worker as root says so in the response and audit', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'node app.js', 'user' => 'root', 'directory' => '/opt/app'])
        ->assertOk()->assertSee('Worker 20 created on server web-1 as root.');

    expect(wrkSent('POST', '/servers/1/background-processes'))->toMatchArray(['user' => 'root', 'directory' => '/opt/app']);
    expect(AuditEntry::query()->latest('id')->first()->summary)->toContain('as root');
});

it('create_worker rejects invalid user and process counts', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'x', 'user' => 'nobody'])->assertHasErrors();
    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'x', 'processes' => 21])->assertHasErrors();
    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'x', 'processes' => 0])->assertHasErrors();
});

it('create_worker with site_id needs configure_workers on that site', function () {
    $user = wrkUser([
        [Capability::ConfigureWorkers, ScopeType::Global, new Target, false],
        [Capability::ConfigureWorkers, ScopeType::Site, new Target('acme', 1, 13), true],
    ]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'x', 'site_id' => 12])
        ->assertHasErrors(['not permitted']);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'x', 'site_id' => 13])
        ->assertOk();
});

it('create_worker rejects a site that is not on the server and does not post', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'x', 'site_id' => 99])
        ->assertHasErrors(['Site 99 is not on server 1.']);

    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('a worker spanning several sites needs permission on every candidate site', function (string $tool, string $method, Capability $cap) {
    // Worker 9 lives in /home/forge, so sites 12 and 13 are both candidates.
    $user = wrkUser([
        [$cap, ScopeType::Global, new Target, true],
        [$cap, ScopeType::Site, new Target('acme', 1, 13), false],
    ]);

    ForgeServer::actingAs($user)->tool($tool, ['server_id' => 1, 'worker_id' => 9])
        ->assertHasErrors(['not permitted']);

    expect(AuditEntry::query()->latest('id')->first()->outcome)->toBe('denied');
    wrkAssertNoWrite(9);
})->with([
    [RestartWorker::class, 'POST', Capability::ManageWorkers],
    [StopWorker::class, 'POST', Capability::ManageWorkers],
    [StartWorker::class, 'POST', Capability::ManageWorkers],
    [DeleteWorker::class, 'DELETE', Capability::ConfigureWorkers],
]);

it('update_worker on a multi-site worker is denied when any candidate site is denied', function () {
    $user = wrkUser([
        [Capability::ConfigureWorkers, ScopeType::Global, new Target, true],
        [Capability::ConfigureWorkers, ScopeType::Site, new Target('acme', 1, 12), false],
    ]);

    ForgeServer::actingAs($user)->tool(UpdateWorker::class, ['server_id' => 1, 'worker_id' => 9, 'name' => 'n'])
        ->assertHasErrors(['not permitted']);
    wrkAssertNoWrite(9);
});

it('a worker with no candidate site requires server-level permission', function (string $tool, Capability $cap) {
    $user = wrkUser([
        [$cap, ScopeType::Global, new Target, true],
        [$cap, ScopeType::Server, new Target('acme', 1), false],
    ]);

    ForgeServer::actingAs($user)->tool($tool, ['server_id' => 1, 'worker_id' => 6, 'name' => 'n'])
        ->assertHasErrors(['not permitted']);

    expect(AuditEntry::query()->latest('id')->first()->outcome)->toBe('denied');
    wrkAssertNoWrite(6);
})->with([
    [RestartWorker::class, Capability::ManageWorkers],
    [DeleteWorker::class, Capability::ConfigureWorkers],
    [UpdateWorker::class, Capability::ConfigureWorkers],
]);

it('create_worker arguments are audited', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'php artisan queue:work --tries=3', 'user' => 'root'])->assertOk();

    $entry = AuditEntry::query()->latest('id')->first();
    expect($entry->tool)->toBe('create_worker')
        ->and($entry->arguments)->toMatchArray(['command' => 'php artisan queue:work --tries=3', 'user' => 'root']);
});

it('update_worker sends name and config without echoing the config', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(UpdateWorker::class, ['server_id' => 1, 'worker_id' => 5, 'name' => 'renamed', 'config' => '[program:x] command=foo'])
        ->assertOk()->assertSee('Worker 5 updated on server web-1.')->assertDontSee('[program:x]');

    expect(wrkSent('PUT', '/background-processes/5'))->toBe(['name' => 'renamed', 'config' => '[program:x] command=foo']);
    expect(AuditEntry::query()->latest('id')->first()->arguments)->toMatchArray(['config' => '[program:x] command=foo']);
});

it('update_worker requires a name', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(UpdateWorker::class, ['server_id' => 1, 'worker_id' => 5, 'name' => ''])->assertHasErrors();

    wrkAssertNoWrite(5);
});

it('update_worker without config sends only the name', function () use ($configure) {
    $user = wrkUser([$configure]);

    ForgeServer::actingAs($user)->tool(UpdateWorker::class, ['server_id' => 1, 'worker_id' => 5, 'name' => 'renamed'])->assertOk();

    expect(wrkSent('PUT', '/background-processes/5'))->toBe(['name' => 'renamed']);
});

it('delete_worker deletes and is denied across sites', function () {
    $user = wrkUser([
        [Capability::ConfigureWorkers, ScopeType::Global, new Target, true],
        [Capability::ConfigureWorkers, ScopeType::Site, new Target('acme', 1, 13), false],
    ]);

    ForgeServer::actingAs($user)->tool(DeleteWorker::class, ['server_id' => 1, 'worker_id' => 5])
        ->assertOk()->assertSee('Worker 5 deleted from server web-1.');
    expect(wrkSent('DELETE', '/background-processes/5'))->toBe([]);

    ForgeServer::actingAs($user)->tool(DeleteWorker::class, ['server_id' => 1, 'worker_id' => 7])
        ->assertHasErrors(['not permitted']);
    wrkAssertNoWrite(7);
});

it('worker tools are hidden for a fresh token', function () {
    $user = wrkUser([]);

    foreach ([RestartWorker::class, StopWorker::class, StartWorker::class, CreateWorker::class, UpdateWorker::class, DeleteWorker::class] as $tool) {
        ForgeServer::actingAs($user)->tool($tool, ['server_id' => 1, 'worker_id' => 5, 'command' => 'x', 'name' => 'n'])->assertHasErrors();
    }

    Http::assertNothingSent();
});

it('create_worker as root with only a site grant is denied and does not post', function () {
    $user = wrkUser([
        [Capability::ConfigureWorkers, ScopeType::Global, new Target, false],
        [Capability::ConfigureWorkers, ScopeType::Site, new Target('acme', 1, 12), true],
    ]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'node app.js', 'site_id' => 12, 'user' => 'root'])
        ->assertHasErrors(['not permitted on server 1']);

    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    $entry = AuditEntry::query()->latest('id')->first();
    expect($entry->outcome)->toBe('denied')
        ->and($entry->server_id)->toBe(1)
        ->and($entry->site_id)->toBeNull();

    // The same site grant still allows a forge-user worker.
    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'node app.js', 'site_id' => 12])->assertOk();
});

it('create_worker as root is allowed with a server grant, and still needs the site', function () {
    $user = wrkUser([
        [Capability::ConfigureWorkers, ScopeType::Global, new Target, false],
        [Capability::ConfigureWorkers, ScopeType::Server, new Target('acme', 1), true],
        [Capability::ConfigureWorkers, ScopeType::Site, new Target('acme', 1, 13), false],
    ]);

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'node app.js', 'site_id' => 12, 'user' => 'root'])
        ->assertOk()->assertSee('as root');
    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'node app.js', 'user' => 'root'])
        ->assertOk();

    ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'node app.js', 'site_id' => 13, 'user' => 'root'])
        ->assertHasErrors(['not permitted on site 13']);
    expect(AuditEntry::query()->latest('id')->first()->outcome)->toBe('denied');
});

it('create_worker with a site only accepts a directory inside that site root', function (string $directory, bool $ok) use ($configure) {
    $user = wrkUser([$configure]);

    $response = ForgeServer::actingAs($user)->tool(CreateWorker::class, ['server_id' => 1, 'command' => 'x', 'site_id' => 12, 'directory' => $directory]);

    if ($ok) {
        $response->assertOk();
        expect(wrkSent('POST', '/servers/1/background-processes'))->toMatchArray(['directory' => $directory]);
    } else {
        $response->assertHasErrors(['must be inside the root directory of site 12']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
})->with([
    'root itself' => ['/home/forge/alpha.test', true],
    'root with trailing slash' => ['/home/forge/alpha.test/', true],
    'below the root' => ['/home/forge/alpha.test/current', true],
    'another site' => ['/home/forge/beta.test', false],
    'prefix without boundary' => ['/home/forge/alpha.test.bak', false],
    'parent' => ['/home/forge', false],
    'escape with dot-dot' => ['/home/forge/alpha.test/../beta.test', false],
    'relative' => ['alpha.test', false],
    'elsewhere' => ['/root', false],
]);

it('update_worker replacing the config needs configure_workers on the server', function () {
    $user = wrkUser([
        [Capability::ConfigureWorkers, ScopeType::Global, new Target, false],
        [Capability::ConfigureWorkers, ScopeType::Site, new Target('acme', 1, 12), true],
    ]);

    ForgeServer::actingAs($user)->tool(UpdateWorker::class, ['server_id' => 1, 'worker_id' => 5, 'name' => 'n', 'config' => "[program:x]\nuser=root"])
        ->assertHasErrors(['not permitted on server 1']);
    wrkAssertNoWrite(5);
    expect(AuditEntry::query()->latest('id')->first()->outcome)->toBe('denied');

    // Without config the site grant is enough.
    ForgeServer::actingAs($user)->tool(UpdateWorker::class, ['server_id' => 1, 'worker_id' => 5, 'name' => 'n'])->assertOk();
    expect(wrkSent('PUT', '/background-processes/5'))->toBe(['name' => 'n']);
});

it('update_worker with config is allowed with a server grant', function () {
    $user = wrkUser([
        [Capability::ConfigureWorkers, ScopeType::Global, new Target, false],
        [Capability::ConfigureWorkers, ScopeType::Server, new Target('acme', 1), true],
    ]);

    ForgeServer::actingAs($user)->tool(UpdateWorker::class, ['server_id' => 1, 'worker_id' => 5, 'name' => 'n', 'config' => '[program:x]'])->assertOk();
    expect(wrkSent('PUT', '/background-processes/5'))->toBe(['name' => 'n', 'config' => '[program:x]']);
});
