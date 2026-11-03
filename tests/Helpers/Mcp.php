<?php

use App\Mcp\AccessTokens;
use App\Models\ForgeConnection;
use App\Models\User;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\withHeaders;

/*
| Shared helpers for MCP tests. Loaded from tests/Pest.php. Every name is prefixed `mcp` (or MCP_)
| because other test files declare their own global helpers.
*/

const MCP_FORGE_TOKEN = 'forge_mcp_secret_9f8e7d6c5b4a3210zyxwvuts';

/**
 * Create an MCP access token (read-only defaults) and apply extra grants.
 *
 * @param  list<array{0: Capability, 1: ScopeType, 2: Target, 3: bool|null}>  $grants
 */
function mcpToken(User $user, array $grants = []): PersonalAccessToken
{
    return mcpNewToken($user, $grants)->accessToken;
}

/**
 * Like mcpToken() but keeps the plain-text bearer token for HTTP tests.
 *
 * @param  list<array{0: Capability, 1: ScopeType, 2: Target, 3: bool|null}>  $grants
 */
function mcpNewToken(User $user, array $grants = []): NewAccessToken
{
    $new = app(AccessTokens::class)->create($user, 'Claude Desktop');
    $token = $new->accessToken;

    foreach ($grants as [$capability, $scope, $target, $allowed]) {
        app(PermissionEngine::class)->set($token, $capability, $scope, $target, $allowed);
    }

    return $new;
}

/**
 * The owner with a Forge connection exposing the given organizations (null: not connected).
 *
 * @param  list<string>|null  $exposed
 */
function mcpOwner(?array $exposed = ['acme']): User
{
    $user = User::factory()->create();

    if ($exposed !== null) {
        ForgeConnection::query()->create([
            'user_id' => $user->id,
            'token' => MCP_FORGE_TOKEN,
            'token_last_four' => substr(MCP_FORGE_TOKEN, -4),
            'forge_user_name' => 'Ada',
            'forge_user_email' => 'ada@example.com',
            'organizations' => [
                ['slug' => 'acme', 'name' => 'Acme'],
                ['slug' => 'beta', 'name' => 'Beta'],
                ['slug' => 'gamma', 'name' => 'Gamma'],
            ],
            'exposed_organizations' => $exposed,
            'validated_at' => now(),
        ]);
    }

    return $user;
}

/** Authenticate as the user through a real token (what the Sanctum guard does for a bearer token). */
function mcpActAs(User $user, PersonalAccessToken $token): User
{
    $user->withAccessToken($token);

    return $user;
}

/**
 * A Forge JSON:API collection document.
 *
 * @param  list<array<string, mixed>>  $records  each with an `id`
 * @return array<string, mixed>
 */
function mcpForgeCollection(string $type, array $records): array
{
    return [
        'data' => array_map(fn (array $r) => [
            'id' => (string) $r['id'],
            'type' => $type,
            'attributes' => array_diff_key($r, ['id' => true]),
        ], $records),
        'meta' => ['next_cursor' => null],
    ];
}

/** Fake Forge's organization list (acme, beta, gamma). */
function mcpFakeOrganizations(): void
{
    Http::fake(['forge.laravel.com/api/orgs*' => Http::response(mcpForgeCollection('organizations', [
        ['id' => 1, 'slug' => 'acme', 'name' => 'Acme'],
        ['id' => 2, 'slug' => 'beta', 'name' => 'Beta'],
        ['id' => 3, 'slug' => 'gamma', 'name' => 'Gamma'],
    ]))]);
}

/**
 * POST a JSON-RPC request to an MCP endpoint the way an MCP client does. $headers are extra request
 * headers for this call only (e.g. the X-Forge-* defaults a client sets in its .mcp.json).
 *
 * @param  array<string, mixed>  $params
 * @param  array<string, string>  $headers
 */
function mcpPost(string $method, array $params = [], ?string $bearer = null, string $uri = '/mcp', array $headers = []): TestResponse
{
    $defaults = ['Accept' => 'application/json, text/event-stream'];
    if ($bearer !== null) {
        $defaults['Authorization'] = 'Bearer '.$bearer;
    }

    return withHeaders($defaults)->postJson($uri, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params === [] ? [] : ['params' => $params]), $headers);
}

/**
 * Tool names from a tools/list response.
 *
 * @return list<string>
 */
function mcpToolNames(TestResponse $response): array
{
    return array_column($response->json('result.tools') ?? [], 'name');
}
