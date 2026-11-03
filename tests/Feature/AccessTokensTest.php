<?php

use App\Mcp\AccessTokens;
use App\Models\PermissionRule;
use App\Models\User;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\ScopeType;
use App\Permissions\Target;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\PersonalAccessToken;

/** @return list<array<string, mixed>> */
function atRules(PersonalAccessToken $token): array
{
    return PermissionRule::query()
        ->where('personal_access_token_id', $token->id)
        ->orderBy('id')
        ->get(['capability', 'scope_type', 'organization', 'server_id', 'site_id', 'allowed'])
        ->toArray();
}

it('creates an mcp token seeded with the default capabilities', function () {
    $user = User::factory()->create();

    $new = app(AccessTokens::class)->create($user, 'Claude Desktop');

    expect($new->plainTextToken)->toBeString()->not->toBe('')
        ->and($new->accessToken->name)->toBe('Claude Desktop')
        ->and($new->accessToken->abilities)->toBe(['mcp'])
        ->and($new->accessToken->tokenable->is($user))->toBeTrue()
        ->and(atRules($new->accessToken))->toBe([
            ['capability' => 'view', 'scope_type' => 'global', 'organization' => null, 'server_id' => null, 'site_id' => null, 'allowed' => true],
            ['capability' => 'view_logs', 'scope_type' => 'global', 'organization' => null, 'server_id' => null, 'site_id' => null, 'allowed' => true],
        ]);
});

it('copies all rules from another token of the same user', function () {
    $user = User::factory()->create();
    $tokens = app(AccessTokens::class);
    $engine = app(PermissionEngine::class);

    $source = $tokens->create($user, 'Source')->accessToken;
    $engine->set($source, Capability::View, ScopeType::Global, new Target, null);
    $engine->set($source, Capability::Deploy, ScopeType::Server, new Target('acme', 3), true);
    $engine->set($source, Capability::EditEnv, ScopeType::Site, new Target('acme', 3, 12), false);

    $copy = $tokens->create($user, 'Copy', $source)->accessToken;

    $expected = [
        ['capability' => 'view_logs', 'scope_type' => 'global', 'organization' => null, 'server_id' => null, 'site_id' => null, 'allowed' => true],
        ['capability' => 'deploy', 'scope_type' => 'server', 'organization' => 'acme', 'server_id' => 3, 'site_id' => null, 'allowed' => true],
        ['capability' => 'edit_env', 'scope_type' => 'site', 'organization' => 'acme', 'server_id' => 3, 'site_id' => 12, 'allowed' => false],
    ];

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->abilities)->toBe(['mcp'])
        ->and(atRules($copy))->toBe($expected)
        ->and(atRules($source))->toBe($expected);
});

it("rejects copying another user's token", function () {
    $other = app(AccessTokens::class)->create(User::factory()->create(), 'Theirs')->accessToken;
    $user = User::factory()->create();

    expect(fn () => app(AccessTokens::class)->create($user, 'Mine', $other))
        ->toThrow(AuthorizationException::class);

    expect($user->tokens()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(1);
});

it('deletes rules together with the token', function () {
    $token = app(AccessTokens::class)->create(User::factory()->create(), 'Agent')->accessToken;

    $token->delete();

    expect(PermissionRule::query()->count())->toBe(0);
});
