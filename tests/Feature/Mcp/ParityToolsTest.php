<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\GetDeploymentScript;
use App\Mcp\Tools\RebootServer;
use App\Mcp\Tools\ResetDeploymentState;
use App\Mcp\Tools\SetQuickDeploy;
use App\Mcp\Tools\UpdateDeploymentScript;
use App\Models\AuditEntry;
use App\Models\PermissionRule;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const PAR_SECRET = 'par-db-password-8842';

beforeEach(function () {
    ForgeClient::flushCache();
    Cache::flush();
});

function parFake(mixed $script = null): void
{
    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/deployments/script' => $script ?? Http::response(['data' => ['id' => '12', 'type' => 'deploymentScripts', 'attributes' => ['content' => "cd /srv\nDB_PASSWORD=".PAR_SECRET."\nphp artisan migrate"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/environment' => Http::response(['data' => ['id' => '12', 'type' => 'environment', 'attributes' => ['content' => 'DB_PASSWORD='.PAR_SECRET."\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/deployments/*' => Http::response('', 200),
        'forge.laravel.com/api/orgs/acme/servers/1/actions' => Http::response('', 202),
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1],
            ['id' => 13, 'name' => 'beta.test', 'server_id' => 1],
        ])),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [['id' => 1, 'name' => 'web-1']])),
    ]);
}

function parUser(Capability $cap, ?Target $target = null): object
{
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, [[$cap, $target?->siteId !== null ? ScopeType::Site : ScopeType::Global, $target ?? new Target, true]]));

    return $user;
}

/** @return list<HttpRequest> */
function parSent(string $method, string $suffix): array
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

it('get_deployment_script returns the script', function () {
    parFake(Http::response(['data' => ['id' => '12', 'type' => 'deploymentScripts', 'attributes' => ['content' => "git pull\nphp artisan migrate"]]]));
    $user = parUser(Capability::View);

    ForgeServer::actingAs($user)->tool(GetDeploymentScript::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()->assertSee('git pull')->assertSee('php artisan migrate');
    expect(parSent('GET', '/sites/12/deployments/script'))->toHaveCount(1);
});

it('get_deployment_script masks a secret in the script', function () {
    parFake();
    $user = parUser(Capability::View);

    ForgeServer::actingAs($user)->tool(GetDeploymentScript::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()->assertSee('cd /srv')->assertDontSee(PAR_SECRET);
});

it('update_deployment_script sends the content and does not echo it', function () {
    parFake();
    $user = parUser(Capability::ConfigureDeployments);
    $script = "cd /srv\nphp artisan optimize\nphp artisan queue:restart";

    ForgeServer::actingAs($user)->tool(UpdateDeploymentScript::class, ['server_id' => 1, 'site_id' => 12, 'content' => $script])
        ->assertOk()->assertSee('Deployment script updated for site 12 (3 lines).')->assertDontSee('queue:restart');

    $sent = parSent('PUT', '/sites/12/deployments/script');
    expect($sent)->toHaveCount(1)->and($sent[0]->data())->toBe(['content' => $script]);
});

it('update_deployment_script keeps the script in audit arguments but masks secrets in it', function () {
    parFake();
    $user = parUser(Capability::ConfigureDeployments);

    ForgeServer::actingAs($user)->tool(UpdateDeploymentScript::class, ['server_id' => 1, 'site_id' => 12, 'content' => "echo hello-audit\nexport P=".PAR_SECRET])
        ->assertOk();

    $dump = json_encode(DB::table('audit_entries')->get()->all(), JSON_UNESCAPED_SLASHES);
    expect($dump)->toContain('hello-audit')->not->toContain(PAR_SECRET);
});

it('update_deployment_script validates content', function (mixed $content) {
    parFake();
    $user = parUser(Capability::ConfigureDeployments);

    $response = ForgeServer::actingAs($user)->tool(UpdateDeploymentScript::class, ['server_id' => 1, 'site_id' => 12, 'content' => $content]);
    $response->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'empty' => [''],
    'whitespace' => ["  \n "],
    'nul byte' => ["echo a\0b"],
    'too long' => [str_repeat('a', 50001)],
]);

it('update_deployment_script hides a 422 detail that echoes the script', function () {
    parFake(fn () => Http::response(['errors' => ['content' => ['bad: zq-echoed-script-line']]], 422));
    $user = parUser(Capability::ConfigureDeployments);

    ForgeServer::actingAs($user)->tool(UpdateDeploymentScript::class, ['server_id' => 1, 'site_id' => 12, 'content' => 'zq-echoed-script-line'])
        ->assertHasErrors(['Forge rejected the deployment script (422).'])->assertDontSee('zq-echoed');
});

it('set_quick_deploy enables and disables', function () {
    parFake();
    $user = parUser(Capability::ConfigureDeployments);

    ForgeServer::actingAs($user)->tool(SetQuickDeploy::class, ['server_id' => 1, 'site_id' => 12, 'enabled' => true])
        ->assertOk()->assertSee('Quick deploy enabled for alpha.test.');
    expect(parSent('POST', '/sites/12/deployments/push-to-deploy'))->toHaveCount(1);

    ForgeServer::actingAs($user)->tool(SetQuickDeploy::class, ['server_id' => 1, 'site_id' => 12, 'enabled' => false])
        ->assertOk()->assertSee('Quick deploy disabled for alpha.test.');
    expect(parSent('DELETE', '/sites/12/deployments/push-to-deploy'))->toHaveCount(1);
});

it('set_quick_deploy requires enabled', function () {
    parFake();
    $user = parUser(Capability::ConfigureDeployments);

    ForgeServer::actingAs($user)->tool(SetQuickDeploy::class, ['server_id' => 1, 'site_id' => 12])->assertHasErrors();
    Http::assertNothingSent();
});

it('reset_deployment_state resets the status', function () {
    parFake();
    $user = parUser(Capability::Deploy);

    ForgeServer::actingAs($user)->tool(ResetDeploymentState::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()->assertSee('Deployment state reset for alpha.test.');
    expect(parSent('DELETE', '/sites/12/deployments/status'))->toHaveCount(1);
});

it('reboot_server posts the reboot action', function () {
    parFake();
    $user = parUser(Capability::RebootServer);

    ForgeServer::actingAs($user)->tool(RebootServer::class, ['server_id' => 1])
        ->assertOk()->assertSee('Reboot queued for server web-1.');

    $sent = parSent('POST', '/servers/1/actions');
    expect($sent)->toHaveCount(1)->and($sent[0]->data())->toBe(['action' => 'reboot']);
    expect(AuditEntry::query()->latest('id')->first()->outcome->value ?? AuditEntry::query()->latest('id')->first()->outcome)->toBe('success');
});

it('reboot_server ignores a site-level allow', function () {
    parFake();
    $user = mcpOwner(['acme']);
    // Visible through a server 2 allow; the site rule is written directly because the engine refuses to store it.
    $token = mcpToken($user, [[Capability::RebootServer, ScopeType::Server, new Target('acme', 2), true]]);
    PermissionRule::create([
        'personal_access_token_id' => $token->id,
        'capability' => Capability::RebootServer,
        'scope_type' => ScopeType::Site,
        'organization' => 'acme',
        'server_id' => 1,
        'site_id' => 12,
        'allowed' => true,
    ]);
    mcpActAs($user, $token);

    ForgeServer::actingAs($user)->tool(RebootServer::class, ['server_id' => 1])->assertHasErrors(['not permitted']);
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');

    $site = mcpNewToken($user);
    PermissionRule::create([
        'personal_access_token_id' => $site->accessToken->id,
        'capability' => Capability::RebootServer,
        'scope_type' => ScopeType::Site,
        'organization' => 'acme',
        'server_id' => 1,
        'site_id' => 12,
        'allowed' => true,
    ]);
    expect(mcpToolNames(mcpPost('tools/list', bearer: $site->plainTextToken)->assertOk()))->not->toContain('reboot_server');
});

it('configure_deployments on site A does not allow site B', function () {
    parFake();
    $user = parUser(Capability::ConfigureDeployments, new Target('acme', 1, 12));

    ForgeServer::actingAs($user)->tool(SetQuickDeploy::class, ['server_id' => 1, 'site_id' => 12, 'enabled' => true])->assertOk();
    ForgeServer::actingAs($user)->tool(SetQuickDeploy::class, ['server_id' => 1, 'site_id' => 13, 'enabled' => true])->assertHasErrors(['not permitted']);
    ForgeServer::actingAs($user)->tool(UpdateDeploymentScript::class, ['server_id' => 1, 'site_id' => 13, 'content' => 'echo hi'])->assertHasErrors(['not permitted']);

    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/sites/13/deployments/'));
});

it('all five tools are hidden for a fresh token except get_deployment_script', function () {
    $new = mcpNewToken(mcpOwner(['acme']));

    $names = mcpToolNames(mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk());

    expect($names)->toContain('get_deployment_script')
        ->not->toContain('update_deployment_script')->not->toContain('set_quick_deploy')
        ->not->toContain('reset_deployment_state')->not->toContain('reboot_server');
});
