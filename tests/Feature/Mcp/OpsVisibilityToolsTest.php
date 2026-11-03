<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\GetIntegrationStatus;
use App\Mcp\Tools\GetScheduledJobOutput;
use App\Mcp\Tools\GetServerEventOutput;
use App\Mcp\Tools\ListScheduledJobs;
use App\Mcp\Tools\ListServerEvents;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ForgeClient::flushCache();
    Cache::flush();

    $job = fn (int $id, string $freq, string $cmd) => ['id' => (string) $id, 'type' => 'scheduledJobs', 'attributes' => ['command' => $cmd, 'user' => 'forge', 'frequency' => $freq, 'cron' => '* * * * *', 'status' => 'installed']];

    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/integrations/horizon' => Http::response([
            'data' => ['id' => '3', 'type' => 'horizonIntegrations', 'attributes' => ['enabled' => 'true', 'horizon_installed' => true]],
            'included' => [['id' => '77', 'type' => 'backgroundProcesses', 'attributes' => ['status' => 'running', 'command' => 'php artisan horizon']]],
        ]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/integrations/octane' => Http::response([
            'data' => ['id' => '4', 'type' => 'octaneIntegrations', 'attributes' => ['enabled' => 'false', 'octane_installed' => false, 'port' => null]],
        ]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/scheduled-jobs/5/output' => Http::response(['data' => ['attributes' => ['output' => "ran\nAPP_KEY=base64:supersecretvalue123456\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/scheduled-jobs*' => Http::response(['data' => [$job(5, 'minutely', 'php artisan schedule:run')]]),
        'forge.laravel.com/api/orgs/acme/servers/1/scheduled-jobs/6/output' => Http::response(['data' => ['attributes' => ['output' => "server job out\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/scheduled-jobs*' => Http::response(['data' => [$job(6, 'custom', 'backup.sh')]]),
        'forge.laravel.com/api/orgs/acme/servers/1/events/9/output' => Http::response(['data' => ['attributes' => ['output' => "evt\nAPP_KEY=base64:supersecretvalue123456\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/events*' => Http::response(['data' => [
            ['id' => '9', 'type' => 'events', 'attributes' => ['description' => 'Adding database', 'ran_as' => 'forge', 'created_at' => '2026-01-01T00:00:00Z']],
        ]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/environment' => Http::response(['data' => ['attributes' => ['content' => "APP_KEY=base64:supersecretvalue123456\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1],
        ])),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [['id' => 1, 'name' => 'web-1']])),
    ]);
});

function opsUser(array $grants = []): mixed
{
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, $grants));

    return $user;
}

it('integration status lists horizon processes', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(GetIntegrationStatus::class, ['server_id' => 1, 'site_id' => 12, 'integration' => 'horizon'])
        ->assertOk()
        ->assertSee(['horizon: enabled=true installed=true', '77 running php artisan horizon']);
});

it('integration status reports no backing processes', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(GetIntegrationStatus::class, ['server_id' => 1, 'site_id' => 12, 'integration' => 'octane'])
        ->assertOk()
        ->assertSee(['octane: enabled=false installed=false', 'No backing processes reported.']);
});

it('integration status rejects unknown integrations', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(GetIntegrationStatus::class, ['server_id' => 1, 'site_id' => 12, 'integration' => 'pulse'])
        ->assertHasErrors();
});

it('list_scheduled_jobs targets the site when site_id is given', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(ListScheduledJobs::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()
        ->assertSee('5 minutely forge php artisan schedule:run');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sites/12/scheduled-jobs'));
});

it('list_scheduled_jobs targets the server without site_id and shows cron for custom', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(ListScheduledJobs::class, ['server_id' => 1])
        ->assertOk()
        ->assertSee('6 * * * * * forge backup.sh');
});

it('list_scheduled_jobs on a view-denied site is denied', function () {
    $user = opsUser([[Capability::View, ScopeType::Site, new Target('acme', 1, 12), false]]);

    ForgeServer::actingAs($user)->tool(ListScheduledJobs::class, ['server_id' => 1, 'site_id' => 12])
        ->assertHasErrors(['not permitted']);
});

it('scheduled job output masks a secret', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(GetScheduledJobOutput::class, ['server_id' => 1, 'site_id' => 12, 'job_id' => 5])
        ->assertOk()
        ->assertSee('ran')
        ->assertDontSee('supersecretvalue123456');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sites/12/scheduled-jobs/5/output'));
});

it('server scheduled job output reads the server path', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(GetScheduledJobOutput::class, ['server_id' => 1, 'job_id' => 6])
        ->assertOk()
        ->assertSee('server job out');
});

it('scheduled job output needs ViewLogs', function () {
    $user = opsUser([[Capability::ViewLogs, ScopeType::Global, new Target, false]]);

    ForgeServer::actingAs($user)->tool(GetScheduledJobOutput::class, ['server_id' => 1, 'job_id' => 6])
        ->assertNotRegistered();
});

it('list_server_events lists events', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(ListServerEvents::class, ['server_id' => 1])
        ->assertOk()
        ->assertSee('9 2026-01-01T00:00:00Z Adding database (forge)');
});

it('server event output uses server secrets', function () {
    $user = opsUser();

    ForgeServer::actingAs($user)->tool(GetServerEventOutput::class, ['server_id' => 1, 'event_id' => 9])
        ->assertOk()
        ->assertSee('evt')
        ->assertDontSee('supersecretvalue123456');
});
