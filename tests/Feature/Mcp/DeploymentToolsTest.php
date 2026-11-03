<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\DeploySite;
use App\Mcp\Tools\GetDeploymentLog;
use App\Mcp\Tools\GetDeploymentStatus;
use App\Mcp\Tools\ListDeployments;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function deployFakes(array $statuses, string $log = "step 1\nstep 2\n"): void
{
    ForgeClient::flushCache();
    Cache::flush();
    $seq = Http::sequence();
    foreach ($statuses as $status) {
        $seq->push(['data' => ['attributes' => ['status' => $status, 'started_at' => '2026-11-03T10:00:00Z']]]);
    }
    $seq->whenEmpty(Http::response(['data' => ['attributes' => ['status' => end($statuses), 'started_at' => '2026-11-03T10:00:00Z']]]));

    Http::fake([
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/deployments/status' => $seq,
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/deployments/77/log' => Http::response(['data' => ['attributes' => ['output' => $log]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/deployments*' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['data' => ['id' => '77', 'type' => 'deployments', 'attributes' => ['status' => 'running']]])
            : Http::response(['data' => [
                ['id' => '77', 'type' => 'deployments', 'attributes' => ['status' => 'finished', 'started_at' => '2026-11-03T10:00:00Z', 'commit_hash' => 'abcdef1234567', 'commit_message' => "Fix bug\nlong body"]],
                ['id' => '76', 'type' => 'deployments', 'attributes' => ['status' => 'failed', 'started_at' => '2026-11-02T10:00:00Z']],
            ]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites/12/environment' => Http::response(['data' => ['attributes' => ['content' => "APP_KEY=base64:supersecretvalue123456\n"]]]),
        'forge.laravel.com/api/orgs/acme/servers/1/sites*' => Http::response(mcpForgeCollection('sites', [
            ['id' => 12, 'name' => 'alpha.test', 'server_id' => 1],
            ['id' => 13, 'name' => 'beta.test', 'server_id' => 1],
        ])),
        'forge.laravel.com/api/orgs/acme/servers*' => Http::response(mcpForgeCollection('servers', [['id' => 1, 'name' => 'web-1']])),
    ]);
}

function deployUser(array $grants = []): array
{
    $user = mcpOwner(['acme']);
    $token = mcpToken($user, array_merge([[Capability::Deploy, ScopeType::Global, new Target, true]], $grants));
    mcpActAs($user, $token);

    return [$user, $token];
}

$args = ['server_id' => 1, 'site_id' => 12];

it('get_deployment_status shows status', function () use ($args) {
    deployFakes(['running']);
    [$user] = deployUser();

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class, $args)
        ->assertOk()->assertSee(['status: running', 'started_at: 2026-11-03T10:00:00Z']);
});

it('list_deployments renders lines with missing commit fields as dashes', function () use ($args) {
    deployFakes(['finished']);
    [$user] = deployUser();

    ForgeServer::actingAs($user)->tool(ListDeployments::class, $args)
        ->assertOk()
        ->assertSee("77 finished 2026-11-03T10:00:00Z abcdef1 Fix bug\n76 failed 2026-11-02T10:00:00Z - -");
});

it('deploy_site finishes and returns scrubbed log tail', function () use ($args) {
    Sleep::fake();
    deployFakes(['running', 'finished'], "building\nkey=base64:supersecretvalue123456\ndone\n");
    [$user] = deployUser();

    ForgeServer::actingAs($user)->tool(DeploySite::class, $args)
        ->assertOk()
        ->assertSee(['Deployment 77 finished.', 'building', 'done'])
        ->assertDontSee('supersecretvalue123456');
});

it('deploy_site times out with follow-up message', function () use ($args) {
    Sleep::fake();
    deployFakes(['running']);
    [$user] = deployUser();

    ForgeServer::actingAs($user)->tool(DeploySite::class, $args)
        ->assertOk()
        ->assertSee('Deployment 77 is still running. Call get_deployment_status to check progress.');
});

it('deploy_site denied on a site when only another site is allowed', function () use ($args) {
    deployFakes(['finished']);
    $user = mcpOwner(['acme']);
    $token = mcpToken($user, [
        [Capability::Deploy, ScopeType::Site, new Target('acme', 1, 13), true],
    ]);
    mcpActAs($user, $token);

    ForgeServer::actingAs($user)->tool(DeploySite::class, $args)
        ->assertHasErrors(['not permitted']);

    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('deploy_site is hidden for a fresh token', function () {
    $new = mcpNewToken(mcpOwner());

    expect(mcpToolNames(mcpPost('tools/list', bearer: $new->plainTextToken)->assertOk()))->not->toContain('deploy_site');
});

it('get_deployment_log truncates', function () use ($args) {
    $log = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 30)));
    deployFakes(['finished'], $log);
    [$user] = deployUser();

    ForgeServer::actingAs($user)->tool(GetDeploymentLog::class, $args + ['deployment_id' => 77, 'lines' => 5])
        ->assertOk()
        ->assertSee(['[showing last 5 of 30 lines]', 'line 30', 'line 26'])
        ->assertDontSee('line 25');
});
