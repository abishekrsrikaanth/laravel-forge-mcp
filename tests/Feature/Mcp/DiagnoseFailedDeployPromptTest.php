<?php

use App\Mcp\Prompts\DiagnoseFailedDeploy;
use App\Mcp\Servers\ForgeServer;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Http;

it('returns the diagnose instructions with the given ids and calls no Forge endpoint', function () {
    Http::fake();
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, ['server_id' => 1, 'site_id' => 12, 'organization' => 'acme'])
        ->assertOk()
        ->assertSee([
            'Diagnose the most recent deployment of site 12 on server 1 in organization acme.',
            'get_deployment_status',
            'get_deployment_log',
            'Do not deploy or change anything.',
        ]);

    Http::assertNothingSent();
});

it('omits the organization when not given', function () {
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, ['server_id' => 1, 'site_id' => 12])
        ->assertOk()
        ->assertSee('site 12 on server 1.')
        ->assertDontSee('in organization');
});

it('rejects non-positive ids', function () {
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, ['server_id' => 0, 'site_id' => 12])
        ->assertHasErrors();
});

it('is hidden when view_logs is denied everywhere', function () {
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, [[Capability::ViewLogs, ScopeType::Global, new Target, false]]));

    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, ['server_id' => 1, 'site_id' => 12])
        ->assertNotRegistered();
});
