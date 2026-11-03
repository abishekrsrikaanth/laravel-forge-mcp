<?php

use App\Forge\ForgeClient;
use App\Mcp\Prompts\DiagnoseFailedDeploy;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Tools\SetDefaults;
use App\Models\AuditEntry;
use App\Models\TokenDefault;
use App\Permissions\Capability;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
| Forge layout: acme: server 1 (sites 12, 13), server 2 (site 22); beta: server 5 (site 55).
*/
function dtFakes(): void
{
    ForgeClient::flushCache();
    Cache::flush();
    $sites = ['acme' => [1 => [12, 13], 2 => [22]], 'beta' => [5 => [55]]];

    Http::fake(function (HttpRequest $request) use ($sites) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (preg_match('#/orgs/(\w+)/servers/(\d+)/sites/(\d+)/deployments/status$#', $path, $m)) {
            return Http::response(['data' => ['attributes' => ['status' => 's'.$m[3]]]]);
        }
        if (preg_match('#/sites/(\d+)/environment$#', $path)) {
            return Http::response(['data' => ['attributes' => ['content' => "APP_KEY=base64:supersecretvalue123456\n"]]]);
        }
        if (preg_match('#/orgs/(\w+)/servers/(\d+)/sites$#', $path, $m)) {
            return Http::response(mcpForgeCollection('sites', array_map(
                fn (int $id) => ['id' => $id, 'name' => "site-{$id}.test", 'server_id' => (int) $m[2]],
                $sites[$m[1]][(int) $m[2]] ?? [],
            )));
        }
        if (preg_match('#/orgs/(\w+)/servers$#', $path, $m)) {
            return Http::response(mcpForgeCollection('servers', array_map(
                fn (int $id) => ['id' => $id, 'name' => "web-{$id}"],
                array_keys($sites[$m[1]] ?? []),
            )));
        }
        if (str_ends_with($path, '/orgs')) {
            return Http::response(mcpForgeCollection('organizations', [
                ['id' => 1, 'slug' => 'acme', 'name' => 'Acme'],
                ['id' => 2, 'slug' => 'beta', 'name' => 'Beta'],
            ]));
        }

        return Http::response(['message' => 'unexpected '.$path], 404);
    });
}

function dtOwner(array $exposed = ['acme'], ?array $default = null, array $grants = []): array
{
    $user = mcpOwner($exposed);
    $new = mcpNewToken($user, $grants);

    if ($default !== null) {
        TokenDefault::query()->create(['personal_access_token_id' => $new->accessToken->id] + $default);
    }

    mcpActAs($user, $new->accessToken);

    return [$user, $new->accessToken, $new->plainTextToken];
}

function dtCall(string $bearer, string $tool, array $arguments = [], array $headers = []): array
{
    $response = mcpPost('tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $bearer, headers: $headers)->assertOk();

    return [(bool) $response->json('result.isError'), (string) $response->json('result.content.0.text')];
}

it('set_defaults stores defaults that a later no-argument call uses', function () {
    dtFakes();
    [, $token, $bearer] = dtOwner();

    expect(dtCall($bearer, 'set_defaults', ['server_id' => 1, 'site_id' => 12]))
        ->toBe([false, 'Defaults saved for this access token (acme / server web-1 (1) / site site-12.test (12)).']);

    $row = TokenDefault::query()->sole();
    expect($row->personal_access_token_id)->toBe($token->id)->and($row->organization)->toBe('acme')
        ->and($row->server_id)->toBe(1)->and($row->site_id)->toBe(12);

    expect(dtCall($bearer, 'get_deployment_status')[1])->toStartWith('status: s12');

    // Upsert: a second call replaces the row, dropping the site.
    expect(dtCall($bearer, 'set_defaults', ['organization' => 'acme', 'server_id' => 2])[1])
        ->toBe('Defaults saved for this access token (acme / server web-2 (2)).');
    $row = TokenDefault::query()->sole();
    expect($row->server_id)->toBe(2)->and($row->site_id)->toBeNull();
});

it('set_defaults writes only to the requesting token', function () {
    dtFakes();
    [$user, , $bearer] = dtOwner();
    $other = mcpToken($user);

    dtCall($bearer, 'set_defaults', ['server_id' => 1]);

    expect(TokenDefault::query()->where('personal_access_token_id', $other->id)->exists())->toBeFalse();
});

it('set_defaults rejects a site that is not on the server', function () {
    dtFakes();
    [, , $bearer] = dtOwner();

    expect(dtCall($bearer, 'set_defaults', ['server_id' => 1, 'site_id' => 22]))->toBe([true, 'Site 22 is not on server 1.']);
    expect(dtCall($bearer, 'set_defaults', ['server_id' => 99]))->toBe([true, 'Server 99 is not in organization acme.']);
    expect(TokenDefault::query()->count())->toBe(0);
});

it('set_defaults denies an unexposed organization and audits it', function () {
    dtFakes();
    [$user] = dtOwner(['acme']);

    ForgeServer::actingAs($user)->tool(SetDefaults::class, ['organization' => 'beta', 'server_id' => 5])
        ->assertHasErrors(["Organization 'beta' is not available to this MCP server."]);

    expect(AuditEntry::query()->sole()->outcome)->toBe('denied')->and(TokenDefault::query()->count())->toBe(0);
});

it('set_defaults is denied and audited when View is denied on the site or server', function () {
    dtFakes();
    [, , $bearer] = dtOwner(grants: [
        [Capability::View, ScopeType::Site, new Target('acme', 1, 13), false],
        [Capability::View, ScopeType::Server, new Target('acme', 2), false],
    ]);

    [$isError, $text] = dtCall($bearer, 'set_defaults', ['server_id' => 1, 'site_id' => 13]);
    expect($isError)->toBeTrue()->and($text)->toContain('is not permitted on');
    expect(AuditEntry::query()->latest('id')->first()->outcome)->toBe('denied');

    [$isError, $text] = dtCall($bearer, 'set_defaults', ['server_id' => 2]);
    expect($isError)->toBeTrue()->and($text)->toContain('is not permitted on');
    expect(AuditEntry::query()->count())->toBe(2)->and(TokenDefault::query()->count())->toBe(0);
});

it('set_defaults needs an organization when several are exposed and never resolves its inputs from defaults', function () {
    dtFakes();
    [$user] = dtOwner(['acme', 'beta'], ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->tool(SetDefaults::class, ['server_id' => 1])
        ->assertHasErrors(['Several organizations are available (acme, beta). Pass the `organization` argument.']);

    ForgeServer::actingAs($user)->tool(SetDefaults::class, ['organization' => 'acme'])
        ->assertHasErrors(['server_id is required.']);

    expect(TokenDefault::query()->sole()->site_id)->toBe(12);
});

it('set_defaults ignores project headers for its own inputs', function () {
    dtFakes();
    [, , $bearer] = dtOwner();

    expect(dtCall($bearer, 'set_defaults', [], ['X-Forge-Server' => '1']))->toBe([true, 'server_id is required.']);
});

it('get_defaults reports none, token and header sources', function () {
    dtFakes();
    [, , $bearer] = dtOwner();

    expect(dtCall($bearer, 'get_defaults'))->toBe([false, 'No defaults are active. Use set_defaults to store some for this access token.']);

    dtCall($bearer, 'set_defaults', ['server_id' => 1, 'site_id' => 12]);

    expect(dtCall($bearer, 'get_defaults')[1])
        ->toBe('Effective defaults (from this access token): acme / server web-1 (1) / site site-12.test (12).');

    $text = dtCall($bearer, 'get_defaults', [], ['X-Forge-Organization' => 'acme', 'X-Forge-Server' => '2'])[1];
    expect($text)->toContain('Effective defaults (from project headers): acme / server web-2 (2).')
        ->and($text)->toContain('Stored on this token (overridden by project headers): acme / server web-1 (1) / site site-12.test (12).');

    // Headers with no stored defaults: no stored line.
    TokenDefault::query()->delete();
    expect(dtCall($bearer, 'get_defaults', [], ['X-Forge-Server' => '2'])[1])
        ->toBe('Effective defaults (from project headers): server web-2 (2).');
});

it('clear_defaults deletes the row and notes project headers', function () {
    dtFakes();
    [, , $bearer] = dtOwner(default: ['organization' => 'acme', 'server_id' => 1]);

    expect(dtCall($bearer, 'clear_defaults', [], ['X-Forge-Server' => '2'])[1])
        ->toBe('Defaults cleared for this access token. Project headers still apply and override any token defaults.');
    expect(TokenDefault::query()->count())->toBe(0);

    expect(dtCall($bearer, 'clear_defaults'))->toBe([false, 'This access token has no stored defaults.']);
});

it('list_organizations appends the active defaults', function () {
    dtFakes();
    [, , $bearer] = dtOwner(default: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    expect(dtCall($bearer, 'list_organizations')[1])->toContain("\nDefaults: acme / server 1 / site 12 (from this access token)");
    expect(dtCall($bearer, 'list_organizations', [], ['X-Forge-Organization' => 'acme', 'X-Forge-Server' => '2'])[1])
        ->toContain('Defaults: acme / server 2 (from project headers)');

    TokenDefault::query()->delete();
    expect(dtCall($bearer, 'list_organizations')[1])->not->toContain('Defaults:');
});

it('lists the defaults tools for a fresh token, with the right annotations', function () {
    [, , $bearer] = dtOwner();

    $tools = collect(mcpPost('tools/list', bearer: $bearer)->json('result.tools'))->keyBy('name');
    expect($tools->has('set_defaults'))->toBeTrue()->and($tools->has('get_defaults'))->toBeTrue()->and($tools->has('clear_defaults'))->toBeTrue();
    expect($tools['get_defaults']['annotations']['readOnlyHint'] ?? null)->toBeTrue()
        ->and($tools['set_defaults']['annotations']['destructiveHint'] ?? false)->toBeFalse()
        ->and($tools['clear_defaults']['annotations']['destructiveHint'] ?? false)->toBeFalse();
});

it('deleting the token cascades its defaults', function () {
    dtFakes();
    [, $token] = dtOwner(default: ['organization' => 'acme', 'server_id' => 1]);

    $token->delete();

    expect(TokenDefault::query()->count())->toBe(0);
});

it('an agent cannot forge the _defaults audit marker', function () {
    dtFakes();
    [, , $bearer] = dtOwner();

    dtCall($bearer, 'get_deployment_status', ['server_id' => 1, 'site_id' => 12, '_defaults' => 'header']);

    $entry = AuditEntry::query()->latest('id')->first();
    expect($entry->outcome)->toBe('success')->and($entry->arguments)->not->toHaveKey('_defaults');
});

it('diagnose_failed_deploy fills server and site from token defaults', function () {
    [$user] = dtOwner(default: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, [])
        ->assertOk()->assertSee('site 12 on server 1 in organization acme.');

    // An explicit server that differs from the default server does not take the default site.
    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, ['server_id' => 2])
        ->assertHasErrors(['server_id and site_id are required (no default site is configured).']);

    // Explicit ids win.
    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, ['server_id' => 2, 'site_id' => 22])
        ->assertSee('site 22 on server 2');
});

it('diagnose_failed_deploy uses the header bundle over token defaults and errors when nothing is configured', function () {
    [$user, , $bearer] = dtOwner(default: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    // Before any HTTP call: no project headers are in play.
    TokenDefault::query()->delete();
    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, [])
        ->assertHasErrors(['server_id and site_id are required (no default site is configured).']);

    TokenDefault::query()->create(['personal_access_token_id' => $user->currentAccessToken()->id, 'organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    $text = mcpPost('prompts/get', ['name' => 'diagnose_failed_deploy', 'arguments' => (object) []], $bearer, headers: ['X-Forge-Server' => '2', 'X-Forge-Site' => '22'])
        ->assertOk()->json('result.messages.0.content.text');
    expect($text)->toContain('site 22 on server 2.');
});

it('diagnose_failed_deploy does not apply defaults of another organization or an unexposed default organization', function () {
    [$user] = dtOwner(['acme', 'beta'], ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, ['organization' => 'beta'])
        ->assertHasErrors(['server_id and site_id are required (no default site is configured).']);

    TokenDefault::query()->update(['organization' => 'gamma']);
    ForgeServer::actingAs($user)->prompt(DiagnoseFailedDeploy::class, [])
        ->assertHasErrors(["Organization 'gamma' is not available."]);
});
