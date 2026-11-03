<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetSite;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListSites;
use App\Models\AuditEntry;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Mcp\ProbeServer;
use Tests\Fixtures\Mcp\ServerProbe;

beforeEach(function () {
    ForgeClient::flushCache();

    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 11, 'name' => 'zeta.test', 'php_version' => 'php83', 'deployment_status' => null, 'server_id' => 1],
            ['id' => 12, 'name' => 'alpha.test', 'php_version' => 'php84', 'deployment_status' => 'finished', 'server_id' => 1],
        ])),
        'forge.laravel.com/api/orgs/acme/servers/2/sites*' => Http::response(mcpForgeCollection('sites', [])),
        'forge.laravel.com/api/orgs/acme/servers/1' => Http::response(['data' => [
            'id' => '1', 'type' => 'servers',
            'attributes' => ['name' => 'web-1', 'ip_address' => '10.0.0.1', 'provider' => 'ocean2', 'region' => 'nyc3', 'php_version' => 'php84', 'ubuntu_version' => '24.04', 'database_type' => 'mysql8', 'is_ready' => true, 'secret_notes' => 'hunter2-internal'],
        ]]),
        'forge.laravel.com/api/orgs/acme/servers/2' => Http::response(['message' => 'Not found'], 404),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [
            ['id' => 2, 'name' => 'web-2', 'ip_address' => '10.0.0.2', 'php_version' => 'php83', 'connection_status' => 'disconnected'],
            ['id' => 1, 'name' => 'web-1', 'ip_address' => '10.0.0.1', 'php_version' => 'php84', 'connection_status' => 'connected'],
        ])),
        'forge.laravel.com/api/orgs/acme/sites/12' => Http::response(['data' => [
            'id' => '12', 'type' => 'sites',
            'attributes' => ['name' => 'alpha.test', 'url' => 'https://alpha.test', 'repository' => 'acme/alpha', 'php_version' => 'php84', 'deployment_status' => 'finished', 'quick_deploy' => true, 'root_directory' => '/', 'web_directory' => '/public', 'deployment_script' => 'echo TOPSECRET-SCRIPT'],
        ]]),
        'forge.laravel.com/api/orgs/acme/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 11, 'name' => 'zeta.test', 'php_version' => 'php83', 'deployment_status' => null, 'server_id' => 1],
            ['id' => 12, 'name' => 'alpha.test', 'php_version' => 'php84', 'deployment_status' => 'finished', 'server_id' => 1],
            ['id' => 13, 'name' => 'beta.test', 'php_version' => 'php84', 'deployment_status' => 'finished', 'server_id' => 2],
            ['id' => 14, 'name' => 'orphan.test', 'php_version' => 'php84', 'deployment_status' => 'finished'],
        ])),
    ]);
});

function serverSiteUser(array $grants = []): array
{
    $user = mcpOwner(['acme']);
    $token = mcpToken($user, $grants);
    mcpActAs($user, $token);

    return [$user, $token];
}

it('list_servers lists servers sorted by name', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(ListServers::class)
        ->assertOk()
        ->assertSee("1 web-1 10.0.0.1 php:php84 connected\n2 web-2 10.0.0.2 php:php83 disconnected");
});

it('list_servers hides a view-denied server', function () {
    [$user] = serverSiteUser([[Capability::View, ScopeType::Server, new Target('acme', 2), false]]);

    ForgeServer::actingAs($user)->tool(ListServers::class)
        ->assertOk()
        ->assertSee('web-1')
        ->assertDontSee('web-2');
});

it('list_servers says so when nothing is visible', function () {
    [$user] = serverSiteUser([
        [Capability::View, ScopeType::Server, new Target('acme', 1), false],
        [Capability::View, ScopeType::Server, new Target('acme', 2), false],
    ]);

    ForgeServer::actingAs($user)->tool(ListServers::class)
        ->assertOk()
        ->assertSee('No servers visible to this access token.');
});

it('get_server shows only the listed fields', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(GetServer::class, ['server_id' => 1])
        ->assertOk()
        ->assertSee(['name: web-1', 'ip_address: 10.0.0.1', 'provider: ocean2', 'region: nyc3', 'php_version: php84', 'ubuntu_version: 24.04', 'database_type: mysql8', 'is_ready: yes'])
        ->assertDontSee('hunter2-internal');
});

it('get_server for a server outside the organization returns an error without fetching it', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(GetServer::class, ['server_id' => 99])
        ->assertHasErrors(['Server 99 is not in organization acme.']);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/servers/99'));
});

it('get_server for a missing id returns the 404 message', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(GetServer::class, ['server_id' => 2])
        ->assertHasErrors(['Forge could not find the requested resource (404)']);

    expect(AuditEntry::query()->sole()->outcome)->toBe('error');
});

it('get_server on a view-denied server returns a permission error and audits denied', function () {
    [$user] = serverSiteUser([[Capability::View, ScopeType::Server, new Target('acme', 1), false]]);

    ForgeServer::actingAs($user)->tool(GetServer::class, ['server_id' => 1])
        ->assertHasErrors(['not permitted']);

    expect(AuditEntry::query()->sole()->outcome)->toBe('denied');
});

it('list_sites lists a servers sites sorted by name', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(ListSites::class, ['server_id' => 1])
        ->assertOk()
        ->assertSee("12 alpha.test server:1 php:php84 deploy:finished\n11 zeta.test server:1 php:php83");
});

it('list_sites without a server lists organization sites and skips records without a server id', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(ListSites::class)
        ->assertOk()
        ->assertSee("12 alpha.test server:1 php:php84 deploy:finished\n13 beta.test server:2 php:php84 deploy:finished\n11 zeta.test server:1 php:php83")
        ->assertDontSee('orphan.test');
});

it('list_sites rejects a server outside the organization', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(ListSites::class, ['server_id' => 99])
        ->assertHasErrors(['Server 99 is not in organization acme.']);
});

it('list_sites hides a denied site but keeps siblings', function () {
    [$user] = serverSiteUser([[Capability::View, ScopeType::Site, new Target('acme', 1, 12), false]]);

    ForgeServer::actingAs($user)->tool(ListSites::class)
        ->assertOk()
        ->assertSee(['zeta.test', 'beta.test'])
        ->assertDontSee('alpha.test');
});

it('list_sites works for a token that can view only one site', function () {
    [$user] = serverSiteUser([
        [Capability::View, ScopeType::Global, new Target, false],
        [Capability::View, ScopeType::Site, new Target('acme', 1, 12), true],
    ]);

    ForgeServer::actingAs($user)->tool(ListSites::class, ['server_id' => 1])
        ->assertOk()
        ->assertSee('alpha.test')
        ->assertDontSee('zeta.test');
});

it('list_sites says so when nothing is visible', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(ListSites::class, ['server_id' => 2])
        ->assertOk()
        ->assertSee('No sites visible to this access token.');
});

it('get_site shows only the listed fields', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(GetSite::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()
        ->assertSee(['name: alpha.test', 'url: https://alpha.test', 'repository: acme/alpha', 'php_version: php84', 'deployment_status: finished', 'quick_deploy: on', 'root_directory: /', 'web_directory: /public'])
        ->assertDontSee('TOPSECRET-SCRIPT');
});

it('get_site on a site not on the server returns an error', function () {
    [$user] = serverSiteUser();

    ForgeServer::actingAs($user)->tool(GetSite::class, ['server_id' => 1, 'site_id' => 13])
        ->assertHasErrors(['Site 13 is not on server 1.']);
});

it('get_site on a view-denied site returns permission error and audits denied', function () {
    [$user] = serverSiteUser([[Capability::View, ScopeType::Site, new Target('acme', 1, 12), false]]);

    ForgeServer::actingAs($user)->tool(GetSite::class, ['server_id' => 1, 'site_id' => 12])
        ->assertHasErrors(['not permitted']);

    $entry = AuditEntry::query()->sole();
    expect([$entry->outcome, $entry->tool, $entry->site_id])->toBe(['denied', 'get_site', 12]);
});

it('serverTarget refuses a server that is not in the organization', function () {
    [$user] = serverSiteUser();

    ProbeServer::actingAs($user)->tool(ServerProbe::class, ['organization' => 'acme', 'server_id' => 1])->assertOk();
    ProbeServer::actingAs($user)->tool(ServerProbe::class, ['organization' => 'acme', 'server_id' => 99])
        ->assertHasErrors(['Server 99 is not in organization acme.']);
});
