<?php

use App\Forge\ForgeClient;
use App\Mcp\Servers\ForgeServer;
use App\Mcp\Support\Defaults;
use App\Mcp\Tools\GetDeploymentStatus;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\ListScheduledJobs;
use App\Models\AuditEntry;
use App\Models\TokenDefault;
use App\Models\User;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Sanctum\PersonalAccessToken;

/*
| Forge layout used here:
|   acme: server 1 (sites 12, 13), server 2 (site 22)
|   beta: server 5 (site 55)
| The deployment status endpoint answers "status: s{siteId}" so each test can see which site was used.
*/
function defaultsFakes(): void
{
    ForgeClient::flushCache();
    Cache::flush();

    $sites = ['acme' => [1 => [12, 13], 2 => [22]], 'beta' => [5 => [55]]];

    Http::fake(function (HttpRequest $request) use ($sites) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (preg_match('#/orgs/(\w+)/servers/(\d+)/sites/(\d+)/deployments/status$#', $path, $m)) {
            return Http::response(['data' => ['attributes' => ['status' => 's'.$m[3], 'started_at' => '2026-11-03T10:00:00Z']]]);
        }
        if (preg_match('#/orgs/(\w+)/servers/(\d+)/sites/(\d+)/scheduled-jobs$#', $path, $m)) {
            return Http::response(mcpForgeCollection('scheduledJobs', [['id' => 900, 'frequency' => 'daily', 'user' => 'forge', 'command' => 'site-job']]));
        }
        if (preg_match('#/orgs/(\w+)/servers/(\d+)/scheduled-jobs$#', $path, $m)) {
            return Http::response(mcpForgeCollection('scheduledJobs', [['id' => 800, 'frequency' => 'hourly', 'user' => 'forge', 'command' => 'server-job']]));
        }
        if (preg_match('#/orgs/(\w+)/servers/(\d+)/sites$#', $path, $m)) {
            return Http::response(mcpForgeCollection('sites', array_map(
                fn (int $id) => ['id' => $id, 'name' => "site-{$id}.test", 'server_id' => (int) $m[2]],
                $sites[$m[1]][(int) $m[2]] ?? [],
            )));
        }
        if (preg_match('#/orgs/(\w+)/servers/(\d+)$#', $path, $m)) {
            return Http::response(['data' => ['id' => $m[2], 'type' => 'servers', 'attributes' => ['name' => 'web-'.$m[2], 'ip_address' => '10.0.0.'.$m[2]]]]);
        }
        if (preg_match('#/orgs/(\w+)/servers$#', $path, $m)) {
            return Http::response(mcpForgeCollection('servers', array_map(
                fn (int $id) => ['id' => $id, 'name' => "web-{$id}"],
                array_keys($sites[$m[1]] ?? []),
            )));
        }

        return Http::response(['message' => 'unexpected '.$path], 404);
    });
}

/** @return array{0: User, 1: PersonalAccessToken, 2: string} owner, token, plain-text bearer */
function defaultsOwner(array $exposed = ['acme'], ?array $tokenDefault = null, array $grants = []): array
{
    $user = mcpOwner($exposed);
    $new = mcpNewToken($user, $grants);
    $token = $new->accessToken;

    if ($tokenDefault !== null) {
        TokenDefault::query()->create(['personal_access_token_id' => $token->id] + $tokenDefault);
    }

    mcpActAs($user, $token);

    return [$user, $token, $new->plainTextToken];
}

function defaultsCall(string $bearer, string $tool, array $arguments = [], array $headers = []): array
{
    $response = mcpPost('tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $bearer, headers: $headers)->assertOk();

    return [(bool) $response->json('result.isError'), (string) $response->json('result.content.0.text')];
}

it('precedence is explicit argument > header > token default', function () {
    defaultsFakes();
    [, , $bearer] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    // Token default only.
    [$isError, $text] = defaultsCall($bearer, 'get_deployment_status');
    expect($isError)->toBeFalse()->and($text)->toStartWith("status: s12\nstarted_at: 2026-11-03T10:00:00Z");

    // Headers win over the token default.
    $headers = ['X-Forge-Organization' => 'acme', 'X-Forge-Server' => '2', 'X-Forge-Site' => '22'];
    expect(defaultsCall($bearer, 'get_deployment_status', [], $headers)[1])->toStartWith('status: s22');

    // Explicit arguments win over headers.
    expect(defaultsCall($bearer, 'get_deployment_status', ['server_id' => 1, 'site_id' => 13], $headers)[1])->toStartWith('status: s13');

    // A default server applies to an explicit site on that server.
    expect(defaultsCall($bearer, 'get_deployment_status', ['site_id' => 13])[1])->toStartWith('status: s13');
});

it('uses the header bundle and ignores token defaults when any X-Forge header is present', function () {
    defaultsFakes();
    [, , $bearer] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    expect(defaultsCall($bearer, 'get_deployment_status', [], ['X-Forge-Organization' => 'acme']))
        ->toBe([true, 'server_id is required (no default server is configured for this project or token).']);

    // Server header alone: the token's default site is not merged in.
    expect(defaultsCall($bearer, 'get_deployment_status', [], ['X-Forge-Server' => '1']))
        ->toBe([true, 'site_id is required (no default site is configured for server 1).']);
});

it('does not apply the default site when an explicit server differs from the default server', function () {
    defaultsFakes();
    [$user] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class, ['server_id' => 2])
        ->assertHasErrors(['site_id is required (no default site is configured for server 2).']);
});

it('does not apply defaults for one organization when another is resolved', function () {
    defaultsFakes();
    [$user, , $bearer] = defaultsOwner(['acme', 'beta'], ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class, ['organization' => 'beta'])
        ->assertHasErrors(['server_id is required (no default server is configured for this project or token).']);

    expect(defaultsCall($bearer, 'get_deployment_status', ['organization' => 'beta'], ['X-Forge-Organization' => 'acme', 'X-Forge-Server' => '1', 'X-Forge-Site' => '12']))
        ->toBe([true, 'server_id is required (no default server is configured for this project or token).']);

    // With no default organization, a default server applies to whichever organization is resolved.
    expect(defaultsCall($bearer, 'get_deployment_status', ['organization' => 'beta'], ['X-Forge-Server' => '5', 'X-Forge-Site' => '55'])[1])
        ->toStartWith('status: s55');
});

it('uses the default organization ahead of the several-organizations error', function () {
    defaultsFakes();
    [$user] = defaultsOwner(['acme', 'beta'], ['organization' => 'beta', 'server_id' => 5, 'site_id' => 55]);

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class)->assertOk()->assertSee('status: s55');
});

it('rejects invalid headers without echoing their value', function (array $headers, string $message) {
    defaultsFakes();
    [, , $bearer] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    [$isError, $text] = defaultsCall($bearer, 'get_deployment_status', ['server_id' => 1, 'site_id' => 12], $headers);

    expect($isError)->toBeTrue()->and($text)->toBe($message);
    foreach ($headers as $value) {
        if (strlen($value) > 1) {
            expect($text)->not->toContain($value);
        }
    }
})->with([
    'org slug' => [['X-Forge-Organization' => 'acme<script>'], 'Invalid X-Forge-Organization header.'],
    'org too long' => [['X-Forge-Organization' => str_repeat('a', 65)], 'Invalid X-Forge-Organization header.'],
    'org leading dash' => [['X-Forge-Organization' => '-acme'], 'Invalid X-Forge-Organization header.'],
    'server not int' => [['X-Forge-Server' => '1abc'], 'Invalid X-Forge-Server header.'],
    'server zero' => [['X-Forge-Server' => '0'], 'Invalid X-Forge-Server header.'],
    'server negative' => [['X-Forge-Server' => '-3'], 'Invalid X-Forge-Server header.'],
    'site not int' => [['X-Forge-Server' => '1', 'X-Forge-Site' => '12.5'], 'Invalid X-Forge-Site header.'],
    'site without server' => [['X-Forge-Site' => '12'], 'X-Forge-Site requires X-Forge-Server.'],
]);

it('denies and audits a default organization that is not exposed', function () {
    defaultsFakes();
    [$user, $token, $bearer] = defaultsOwner(['acme'], ['organization' => 'gamma', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class)
        ->assertHasErrors(["Organization 'gamma' is not available to this MCP server."]);

    $entry = AuditEntry::query()->sole();
    expect($entry->outcome)->toBe('denied')
        ->and($entry->organization)->toBe('gamma')
        ->and($entry->personal_access_token_id)->toBe($token->id)
        ->and($entry->arguments['_defaults'] ?? null)->toBe('token');

    expect(defaultsCall($bearer, 'get_deployment_status', [], ['X-Forge-Organization' => 'beta', 'X-Forge-Server' => '5', 'X-Forge-Site' => '55']))
        ->toBe([true, "Organization 'beta' is not available to this MCP server."]);
    expect(AuditEntry::query()->latest('id')->first()->arguments['_defaults'] ?? null)->toBe('header');
});

it('rejects a default site that is not on the default server', function () {
    defaultsFakes();
    [$user, , $bearer] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 22]);

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class)->assertHasErrors(['Site 22 is not on server 1.']);

    expect(defaultsCall($bearer, 'get_deployment_status', [], ['X-Forge-Server' => '2', 'X-Forge-Site' => '12']))
        ->toBe([true, 'Site 12 is not on server 2.']);
});

it('runs a site tool with no arguments from token defaults and audits the default source', function () {
    defaultsFakes();
    [$user] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class)->assertOk()->assertSee('status: s12');

    $entry = AuditEntry::query()->sole();
    expect($entry->outcome)->toBe('success')
        ->and([$entry->organization, $entry->server_id, $entry->site_id])->toBe(['acme', 1, 12])
        ->and($entry->arguments)->toBe(['_defaults' => 'token']);
});

it('does not mark arguments as defaulted when everything is explicit', function () {
    defaultsFakes();
    [$user] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class, ['organization' => 'acme', 'server_id' => 1, 'site_id' => 13])->assertOk()->assertSee('status: s13');

    expect(AuditEntry::query()->sole()->arguments)->toBe(['organization' => 'acme', 'server_id' => 1, 'site_id' => 13]);
});

it('runs a server tool from a default server', function () {
    defaultsFakes();
    [$user] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 2, 'site_id' => null]);

    ForgeServer::actingAs($user)->tool(GetServer::class)->assertOk()->assertSee('web-2');
});

it('requires ids when no defaults exist', function () {
    defaultsFakes();
    [$user] = defaultsOwner();

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class)
        ->assertHasErrors(['server_id is required (no default server is configured for this project or token).']);
    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class, ['server_id' => 1])
        ->assertHasErrors(['site_id is required (no default site is configured for server 1).']);
});

it('optional site filters ignore the default site', function () {
    defaultsFakes();
    [$user] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    ForgeServer::actingAs($user)->tool(ListScheduledJobs::class)
        ->assertOk()->assertSee('server-job')->assertDontSee('site-job');
    ForgeServer::actingAs($user)->tool(ListScheduledJobs::class, ['site_id' => 12])
        ->assertOk()->assertSee('site-job');
});

it('a default cannot reach a site the token is denied on', function () {
    defaultsFakes();
    [$user, , $bearer] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);
    $token = $user->currentAccessToken();
    app(PermissionEngine::class)->set($token, Capability::View, ScopeType::Site, new Target('acme', 1, 12), false);

    ForgeServer::actingAs($user)->tool(GetDeploymentStatus::class)
        ->assertHasErrors(['View is not permitted on site 12 on server 1 (acme) for this access token.']);

    expect(defaultsCall($bearer, 'get_deployment_status', [], ['X-Forge-Server' => '1', 'X-Forge-Site' => '12'])[1])
        ->toStartWith('View is not permitted on site 12 on server 1 (acme)');

    $denied = AuditEntry::query()->where('outcome', 'denied')->get();
    expect($denied)->toHaveCount(2)
        ->and($denied->pluck('site_id')->all())->toBe([12, 12])
        ->and($denied->pluck('arguments._defaults')->all())->toBe(['token', 'header']);
});

it('resolves no headers outside an HTTP request and falls back to the token default', function () {
    [, $token] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    $resolved = Defaults::resolve(new Request, $token);

    expect([$resolved->organization, $resolved->serverId, $resolved->siteId, $resolved->source])->toBe(['acme', 1, 12, 'token']);

    $none = Defaults::resolve(new Request, defaultsOwner()[1]);
    expect([$none->organization, $none->serverId, $none->siteId, $none->source])->toBe([null, null, null, null]);
});

it('deletes token defaults with their token', function () {
    [, $token] = defaultsOwner(tokenDefault: ['organization' => 'acme', 'server_id' => 1, 'site_id' => 12]);

    $token->delete();

    expect(TokenDefault::query()->count())->toBe(0);
});

it('no longer marks server_id and site_id as required in tool schemas', function () {
    [, , $bearer] = defaultsOwner();

    $tools = collect(mcpPost('tools/list', bearer: $bearer)->assertOk()->json('result.tools'))->keyBy('name');

    expect($tools['get_deployment_status']['inputSchema']['required'] ?? [])->not->toContain('server_id')->not->toContain('site_id')
        ->and($tools['get_deployment_status']['inputSchema']['properties']['site_id']['description'])->toContain('Defaults to the configured site if omitted.')
        ->and($tools['get_server']['inputSchema']['properties']['server_id']['description'])->toContain('Defaults to the configured server if omitted.');
});
