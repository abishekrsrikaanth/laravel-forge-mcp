<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\ListOrganizations;
use App\Models\AuditEntry;
use App\Models\ForgeConnection;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Mcp\LeakProbe;
use Tests\Fixtures\Mcp\ProbeServer;
use Tests\Fixtures\Mcp\SiteProbe;

beforeEach(fn () => ForgeClient::flushCache());

it('returns only exposed orgs', function () {
    mcpFakeOrganizations();
    $user = mcpOwner(['acme', 'beta']);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->tool(ListOrganizations::class)
        ->assertOk()
        ->assertSee(['acme', 'Acme', 'beta', 'Beta'])
        ->assertDontSee(['gamma', 'Gamma']);
});

it('lists only the organizations the token may view', function () {
    mcpFakeOrganizations();
    $user = mcpOwner(['acme', 'beta']);
    mcpActAs($user, mcpToken($user, [
        [Capability::View, ScopeType::Global, new Target, null],
        [Capability::View, ScopeType::Organization, new Target('beta'), true],
    ]));

    ForgeServer::actingAs($user)->tool(ListOrganizations::class)
        ->assertOk()
        ->assertSee('beta')
        ->assertDontSee('acme');
});

it('filters to the requested organization', function () {
    mcpFakeOrganizations();
    $user = mcpOwner(['acme', 'beta']);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->tool(ListOrganizations::class, ['organization' => 'beta'])
        ->assertOk()
        ->assertSee('beta')
        ->assertDontSee('acme');
});

it('writes a success audit entry with token name', function () {
    mcpFakeOrganizations();
    $user = mcpOwner(['acme']);
    $token = mcpToken($user);
    mcpActAs($user, $token);

    ForgeServer::actingAs($user)->tool(ListOrganizations::class)->assertOk();

    $entry = AuditEntry::query()->sole();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->personal_access_token_id)->toBe($token->id)
        ->and($entry->token_name)->toBe('Claude Desktop')
        ->and($entry->tool)->toBe('list_organizations')
        ->and($entry->outcome)->toBe('success')
        ->and($entry->organization)->toBeNull()
        ->and($entry->server_id)->toBeNull()
        ->and($entry->site_id)->toBeNull()
        ->and($entry->arguments)->toBe([])
        ->and($entry->summary)->toBe('Listed 1 organization.')
        ->and($entry->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($entry->created_at)->not->toBeNull();
});

it('keeps audit entries when the token is deleted', function () {
    mcpFakeOrganizations();
    $user = mcpOwner(['acme']);
    $token = mcpToken($user);
    mcpActAs($user, $token);

    ForgeServer::actingAs($user)->tool(ListOrganizations::class)->assertOk();
    $token->delete();

    expect(AuditEntry::query()->sole()->personal_access_token_id)->toBeNull();
});

it('forge 401 returns reconnect message, audits error, sets last_error', function () {
    Http::fake(['forge.laravel.com/api/orgs*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->tool(ListOrganizations::class)
        ->assertHasErrors(['Forge rejected the API token (401). Reconnect in the Forge MCP dashboard.']);

    expect(AuditEntry::query()->sole()->outcome)->toBe('error')
        ->and(ForgeConnection::query()->sole()->last_error)->toContain('Reconnect in the Forge MCP dashboard');
});

it('clears last_error after a successful call', function () {
    mcpFakeOrganizations();
    $user = mcpOwner(['acme']);
    ForgeConnection::query()->update(['last_error' => 'Forge rejected the API token (401).']);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->tool(ListOrganizations::class)->assertOk();

    expect(ForgeConnection::query()->sole()->last_error)->toBeNull();
});

it('no forge connection returns not-connected message', function () {
    $user = mcpOwner(null);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->tool(ListOrganizations::class)
        ->assertHasErrors(['Forge is not connected. Connect it in the Forge MCP dashboard.']);

    Http::assertNothingSent();
});

it('reports when no organization is exposed', function () {
    $user = mcpOwner([]);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->tool(ListOrganizations::class)
        ->assertHasErrors(['No organizations are exposed to this MCP server.']);

    Http::assertNothingSent();
});

it('unexposed organization argument returns error without calling Forge', function () {
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user));

    ForgeServer::actingAs($user)->tool(ListOrganizations::class, ['organization' => 'gamma'])
        ->assertHasErrors(["Organization 'gamma' is not available to this MCP server."]);

    Http::assertNothingSent();
});

it('defaults the organization when exactly one is exposed and asks when several are', function () {
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user));
    ProbeServer::actingAs($user)->tool(LeakProbe::class)->assertOk();
    expect(AuditEntry::query()->sole()->organization)->toBe('acme');

    $other = mcpOwner(['acme', 'beta']);
    mcpActAs($other, mcpToken($other));
    ProbeServer::actingAs($other)->tool(LeakProbe::class)
        ->assertHasErrors(['Several organizations are available (acme, beta). Pass the `organization` argument.']);
});

it('never exposes the forge token in responses, audit rows or logs', function (string $mode) {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user));

    $response = ProbeServer::actingAs($user)->tool(LeakProbe::class, ['mode' => $mode]);

    $response->assertDontSee(MCP_FORGE_TOKEN);
    expect(json_encode(DB::table('audit_entries')->get()))->not->toContain(MCP_FORGE_TOKEN)
        ->and(DB::table('audit_entries')->count())->toBe(1)
        ->and(implode("\n", $logged))->not->toContain(MCP_FORGE_TOKEN);
})->with(['return', 'raw', 'forge', 'crash']);

it('resolves a site target from the server site list', function () {
    Http::fake(['forge.laravel.com/api/orgs/acme/servers/5/sites*' => Http::response(mcpForgeCollection('sites', [
        ['id' => 11, 'name' => 'app.test'],
        ['id' => 12, 'name' => 'api.test'],
    ]))]);
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user));

    ProbeServer::actingAs($user)->tool(SiteProbe::class, ['server_id' => 5, 'site_id' => 12])
        ->assertOk()
        ->assertSee('site 12 ok');

    $entry = AuditEntry::query()->sole();
    expect([$entry->organization, $entry->server_id, $entry->site_id])->toBe(['acme', 5, 12]);

    ProbeServer::actingAs($user)->tool(SiteProbe::class, ['server_id' => 5, 'site_id' => 99])
        ->assertHasErrors(['Site 99 is not on server 5.']);

    expect(AuditEntry::query()->latest('id')->first()->outcome)->toBe('error');
});

it('checks site permissions against the resolved site target', function () {
    Http::fake(['forge.laravel.com/api/orgs/acme/servers/5/sites*' => Http::response(mcpForgeCollection('sites', [['id' => 12, 'name' => 'api.test']]))]);
    $user = mcpOwner(['acme']);
    mcpActAs($user, mcpToken($user, [
        [Capability::View, ScopeType::Site, new Target('acme', 5, 12), false],
    ]));

    ProbeServer::actingAs($user)->tool(SiteProbe::class, ['server_id' => 5, 'site_id' => 12])
        ->assertHasErrors(['View is not permitted on site 12 on server 5 (acme) for this access token.']);
});
