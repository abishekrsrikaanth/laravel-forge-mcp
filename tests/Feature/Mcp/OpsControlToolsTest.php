<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\DisableMaintenanceMode;
use App\Mcp\Tools\EnableMaintenanceMode;
use App\Mcp\Tools\GetMaintenanceMode;
use App\Mcp\Tools\RestartService;
use App\Models\PermissionRule;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const CTL_SECRET = 'ops-bypass-secret-5531';

beforeEach(function () {
    ForgeClient::flushCache();
    Cache::flush();
});

function ctlFake(array $integration = ['enabled' => true], mixed $enable = null): void
{
    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/services/*' => Http::response('', 202),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/integrations/laravel-maintenance' => $enable ?? Http::sequence()
            ->push(['data' => ['id' => '1', 'type' => 'integrations', 'attributes' => $integration]])
            ->whenEmpty(Http::response('', 202)),
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1],
            ['id' => 13, 'name' => 'beta.test', 'server_id' => 1],
        ])),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [['id' => 1, 'name' => 'web-1']])),
    ]);
}

function ctlUser(Capability $cap, ?Target $target = null): object
{
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, [[$cap, $target?->siteId !== null ? ScopeType::Site : ScopeType::Global, $target ?? new Target, true]]));

    return $user;
}

/** @return list<HttpRequest> */
function ctlSent(string $method, string $suffix): array
{
    $found = [];
    Http::assertSent(function (HttpRequest $r) use ($method, $suffix, &$found) {
        if ($r->method() === $method && str_ends_with($r->url(), $suffix)) {
            $found[] = $r;
        }

        return true;
    });

    return $found;
}

it('restart_service queues a reboot for each service', function (string $service, string $label) {
    ctlFake();
    $user = ctlUser(Capability::RestartServices);

    ForgeServer::actingAs($user)->tool(RestartService::class, ['server_id' => 1, 'service' => $service])
        ->assertOk()->assertSee("{$label} restart queued on server web-1.");

    expect(ctlSent('POST', "/servers/1/services/{$service}/actions")[0]->data())->toBe(['action' => 'reboot']);
})->with([['redis', 'Redis'], ['supervisor', 'Supervisor'], ['mysql', 'MySQL'], ['postgres', 'Postgres']]);

it('restart_service rejects unknown service', function () {
    ctlFake();
    $user = ctlUser(Capability::RestartServices);

    ForgeServer::actingAs($user)->tool(RestartService::class, ['server_id' => 1, 'service' => 'nginx'])->assertHasErrors();
    ForgeServer::actingAs($user)->tool(RestartService::class, ['server_id' => 1])->assertHasErrors();

    Http::assertNothingSent();
});

it('restart_service ignores site-level allow', function () {
    ctlFake();
    $user = mcpOwner(['acme']);
    // Visible via an allow on server 2, so the call reaches the permission check for server 1.
    $token = mcpToken($user, [[Capability::RestartServices, ScopeType::Server, new Target('acme', 2), true]]);
    PermissionRule::create([
        'personal_access_token_id' => $token->id,
        'capability' => Capability::RestartServices,
        'scope_type' => ScopeType::Site,
        'organization' => 'acme',
        'server_id' => 1,
        'site_id' => 12,
        'allowed' => true,
    ]);
    mcpActAs($user, $token);

    ForgeServer::actingAs($user)->tool(RestartService::class, ['server_id' => 1, 'service' => 'redis', 'site_id' => 12])
        ->assertHasErrors(['not permitted']);

    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('get_maintenance_mode reports state', function (array $attrs, string $expected) {
    ctlFake($attrs);
    $user = ctlUser(Capability::View);

    ForgeServer::actingAs($user)->tool(GetMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()->assertSee($expected);
})->with([
    [['enabled' => true, 'status' => null], 'Maintenance mode: on'],
    [['enabled' => false, 'status' => null], 'Maintenance mode: off'],
    [['enabled' => false, 'status' => 'enabling'], 'Maintenance mode: off (status: enabling)'],
    [['enabled' => true, 'status' => 'disabling'], 'Maintenance mode: on (status: disabling)'],
]);

it('enable_maintenance_mode posts the request with defaults', function () {
    ctlFake();
    $user = ctlUser(Capability::MaintenanceMode);

    ForgeServer::actingAs($user)->tool(EnableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()->assertSee('Maintenance mode is being enabled for alpha.test (HTTP 503).');

    expect(ctlSent('POST', '/sites/12/integrations/laravel-maintenance')[0]->data())->toBe(['status' => 503]);
});

it('enable_maintenance_mode forwards status, secret and redirect', function () {
    ctlFake();
    $user = ctlUser(Capability::MaintenanceMode);

    ForgeServer::actingAs($user)->tool(EnableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12, 'status' => 307, 'secret' => CTL_SECRET, 'redirect' => '/down'])
        ->assertOk()->assertSee('(HTTP 307)')->assertDontSee(CTL_SECRET);

    expect(ctlSent('POST', '/sites/12/integrations/laravel-maintenance')[0]->data())->toBe(['status' => 307, 'secret' => CTL_SECRET, 'redirect' => '/down']);
});

it('enable_maintenance_mode validates arguments', function (array $args) {
    ctlFake();
    $user = ctlUser(Capability::MaintenanceMode);

    ForgeServer::actingAs($user)->tool(EnableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12] + $args)->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'bad status' => [['status' => 500]],
    'string status' => [['status' => '503']],
    'secret with whitespace' => [['secret' => 'a b']],
    'secret too long' => [['secret' => str_repeat('a', 129)]],
    'redirect bare host' => [['redirect' => 'example.com']],
    'redirect scheme-relative' => [['redirect' => '//evil.test']],
    'redirect javascript' => [['redirect' => 'javascript:alert(1)']],
    'redirect ftp' => [['redirect' => 'ftp://example.com']],
]);

it('enable_maintenance_mode accepts an https redirect', function () {
    ctlFake();
    $user = ctlUser(Capability::MaintenanceMode);

    ForgeServer::actingAs($user)->tool(EnableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12, 'status' => 410, 'redirect' => 'https://example.com/maint'])->assertOk();

    expect(ctlSent('POST', '/sites/12/integrations/laravel-maintenance')[0]->data())->toBe(['status' => 410, 'redirect' => 'https://example.com/maint']);
});

it('disable_maintenance_mode sends a delete', function () {
    ctlFake();
    $user = ctlUser(Capability::MaintenanceMode);

    ForgeServer::actingAs($user)->tool(DisableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()->assertSee('Maintenance mode is being disabled for alpha.test.');

    expect(ctlSent('DELETE', '/sites/12/integrations/laravel-maintenance'))->toHaveCount(1);
});

it('maintenance secret never appears in response or audit', function () {
    ctlFake();
    $user = ctlUser(Capability::MaintenanceMode);

    ForgeServer::actingAs($user)->tool(EnableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12, 'secret' => CTL_SECRET, 'redirect' => '/x'])
        ->assertOk()->assertDontSee(CTL_SECRET);

    $dump = json_encode(DB::table('audit_entries')->get()->all(), JSON_UNESCAPED_SLASHES);
    expect($dump)->not->toContain(CTL_SECRET)->toContain('[REDACTED]');
    $row = DB::table('audit_entries')->latest('id')->first();
    expect(json_decode($row->arguments, true))->toEqual(['server_id' => 1, 'site_id' => 12, 'secret' => '[REDACTED]', 'redirect' => '/x']);
});

it('a Forge 422 does not echo the secret', function () {
    ctlFake(enable: Http::response(['errors' => [['detail' => 'bad '.CTL_SECRET]]], 422));
    $user = ctlUser(Capability::MaintenanceMode);

    ForgeServer::actingAs($user)->tool(EnableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12, 'secret' => CTL_SECRET])
        ->assertHasErrors(['Forge rejected the maintenance mode request (422).'])->assertDontSee(CTL_SECRET);

    expect(json_encode(DB::table('audit_entries')->get()->all()))->not->toContain(CTL_SECRET);
});

it('maintenance_mode on site A does not allow site B', function () {
    ctlFake();
    $user = ctlUser(Capability::MaintenanceMode, new Target('acme', 1, 12));

    ForgeServer::actingAs($user)->tool(EnableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 12])->assertOk();
    ForgeServer::actingAs($user)->tool(EnableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 13])->assertHasErrors(['not permitted']);
    ForgeServer::actingAs($user)->tool(DisableMaintenanceMode::class, ['server_id' => 1, 'site_id' => 13])->assertHasErrors(['not permitted']);
});

it('fresh token sees only get_maintenance_mode of the ops control tools', function () {
    $new = mcpNewToken(mcpOwner(['acme']));

    $names = mcpToolNames(mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk());

    expect($names)->toContain('get_maintenance_mode')
        ->not->toContain('restart_service')->not->toContain('enable_maintenance_mode')->not->toContain('disable_maintenance_mode');
});
