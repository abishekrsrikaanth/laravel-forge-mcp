<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\RestartNginx;
use App\Mcp\Tools\RestartPhpFpm;
use App\Models\AuditEntry;
use App\Models\PermissionRule;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function svcFakes(): void
{
    ForgeClient::flushCache();
    Cache::flush();
    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/services/*' => Http::response('', 202),
        'forge.laravel.com/api/orgs/acme/sites/12' => Http::response(['data' => ['id' => '12', 'type' => 'sites', 'attributes' => ['name' => 'alpha.test', 'php_version' => 'php83']]]),
        'forge.laravel.com/api/orgs/acme/sites/99' => Http::response(['data' => ['id' => '99', 'type' => 'sites', 'attributes' => ['name' => 'nophp.test']]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1],
            ['id' => 99, 'name' => 'nophp.test', 'server_id' => 1],
        ])),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [
            ['id' => 1, 'name' => 'web-1', 'php_version' => 'php84'],
            ['id' => 2, 'name' => 'bare'],
        ])),
    ]);
}

function svcSiteRule($token): void
{
    // The engine refuses to store site rules for this capability, so write one the way a corrupted/legacy row would exist.
    PermissionRule::create([
        'personal_access_token_id' => $token->id,
        'capability' => Capability::RestartServices,
        'scope_type' => ScopeType::Site,
        'organization' => 'acme',
        'server_id' => 1,
        'site_id' => 12,
        'allowed' => true,
    ]);
}

function svcUser(?array $grants = null): object
{
    $user = mcpOwner(['acme']);
    $token = mcpToken($user, $grants ?? [[Capability::RestartServices, ScopeType::Global, new Target, true]]);
    mcpActAs($user, $token);

    return $user;
}

function svcPosted(string $suffix): ?array
{
    $found = null;
    Http::assertSent(function ($r) use ($suffix, &$found) {
        if ($r->method() === 'POST' && str_ends_with($r->url(), $suffix)) {
            $found = $r->data();
        }

        return true;
    });

    return $found;
}

it('restart_nginx queues a reboot', function () {
    svcFakes();
    $user = svcUser();

    ForgeServer::actingAs($user)->tool(RestartNginx::class, ['server_id' => 1])
        ->assertOk()->assertSee('Nginx restart queued on server web-1.');

    expect(svcPosted('/servers/1/services/nginx/actions'))->toBe(['action' => 'reboot']);
    expect(AuditEntry::query()->latest('id')->first()->summary)->toBe('Restarted nginx on server 1');
});

it('restart_php_fpm reloads with the server php version by default', function () {
    svcFakes();
    $user = svcUser();

    ForgeServer::actingAs($user)->tool(RestartPhpFpm::class, ['server_id' => 1])
        ->assertOk()->assertSee('PHP-FPM php84 reload queued on server web-1.');

    expect(svcPosted('/servers/1/services/php/actions'))->toBe(['action' => 'reload', 'version' => 'php84']);
    expect(AuditEntry::query()->latest('id')->first()->summary)->toBe('PHP-FPM php84 reload on server 1');
});

it('restart_php_fpm maps restart to reboot and uses the site php version', function () {
    svcFakes();
    $user = svcUser();

    ForgeServer::actingAs($user)->tool(RestartPhpFpm::class, ['server_id' => 1, 'action' => 'restart', 'site_id' => 12])
        ->assertOk()->assertSee('PHP-FPM php83 restart queued on server web-1.');

    expect(svcPosted('/servers/1/services/php/actions'))->toBe(['action' => 'reboot', 'version' => 'php83']);
});

it('restart_php_fpm explicit version wins over site and server', function () {
    svcFakes();
    $user = svcUser();

    ForgeServer::actingAs($user)->tool(RestartPhpFpm::class, ['server_id' => 1, 'site_id' => 12, 'version' => 'php82'])
        ->assertOk()->assertSee('PHP-FPM php82 reload queued');

    expect(svcPosted('/servers/1/services/php/actions'))->toBe(['action' => 'reload', 'version' => 'php82']);
});

it('restart_php_fpm falls back to the server version when the site has none', function () {
    svcFakes();
    $user = svcUser();

    ForgeServer::actingAs($user)->tool(RestartPhpFpm::class, ['server_id' => 1, 'site_id' => 99])
        ->assertOk()->assertSee('PHP-FPM php84 reload queued');
});

it('restart_php_fpm errors when no version can be determined', function () {
    svcFakes();
    $user = svcUser();

    ForgeServer::actingAs($user)->tool(RestartPhpFpm::class, ['server_id' => 2])
        ->assertHasErrors(['Could not determine the PHP version; pass version explicitly (e.g. php84).']);

    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('restart_php_fpm rejects invalid versions', function (string $version) {
    svcFakes();
    $user = svcUser();

    ForgeServer::actingAs($user)->tool(RestartPhpFpm::class, ['server_id' => 1, 'version' => $version])
        ->assertHasErrors(['Invalid PHP version']);

    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
})->with(['8.4', 'php8', 'php84; rm -rf', 'php99', 'PHP84']);

it('restart_php_fpm rejects a site that is not on the server', function () {
    svcFakes();
    $user = svcUser();

    ForgeServer::actingAs($user)->tool(RestartPhpFpm::class, ['server_id' => 1, 'site_id' => 55])
        ->assertHasErrors(['Site 55 is not on server 1.']);
});

it('site-level allow does not permit restart', function () {
    svcFakes();
    $user = mcpOwner(['acme']);
    // Visible via an allow on server 2, so the call reaches the permission check for server 1.
    $token = mcpToken($user, [[Capability::RestartServices, ScopeType::Server, new Target('acme', 2), true]]);
    svcSiteRule($token);
    mcpActAs($user, $token);

    ForgeServer::actingAs($user)->tool(RestartPhpFpm::class, ['server_id' => 1, 'site_id' => 12, 'version' => 'php84'])
        ->assertHasErrors(['not permitted']);
    ForgeServer::actingAs($user)->tool(RestartNginx::class, ['server_id' => 1])
        ->assertHasErrors(['not permitted']);

    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('restart tools are hidden for a fresh token and for a site-level allow', function () {
    $new = mcpNewToken(mcpOwner());
    expect(mcpToolNames(mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk()))
        ->not->toContain('restart_nginx')->not->toContain('restart_php_fpm');

    $user = mcpOwner(['acme']);
    $site = mcpNewToken($user);
    svcSiteRule($site->accessToken);
    expect(mcpToolNames(mcpPost('tools/list', bearer: $site->plainTextToken)->assertOk()))
        ->not->toContain('restart_nginx')->not->toContain('restart_php_fpm');
});

it('restart tools appear with a server-level allow', function () {
    $user = mcpOwner(['acme']);
    $new = mcpNewToken($user, [[Capability::RestartServices, ScopeType::Server, new Target('acme', 1), true]]);

    expect(mcpToolNames(mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk()))
        ->toContain('restart_nginx')->toContain('restart_php_fpm');
});

it('restart_nginx denied on another server', function () {
    svcFakes();
    $user = svcUser([[Capability::RestartServices, ScopeType::Server, new Target('acme', 2), true]]);

    ForgeServer::actingAs($user)->tool(RestartNginx::class, ['server_id' => 1])
        ->assertHasErrors(['not permitted']);
});
