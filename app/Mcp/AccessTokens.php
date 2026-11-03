<?php

namespace App\Mcp;

use App\Models\PermissionRule;
use App\Models\User;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\ScopeType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues MCP access tokens. A new token is read-only (global allow for the default capabilities)
 * unless it copies every rule from another token owned by the same user.
 */
final readonly class AccessTokens
{
    public const string ABILITY = 'mcp';

    public function __construct(private PermissionEngine $engine) {}

    /** @throws AuthorizationException when $copyFrom belongs to someone else */
    public function create(User $user, string $name, ?PersonalAccessToken $copyFrom = null): NewAccessToken
    {
        if ($copyFrom !== null && ! $this->ownedBy($copyFrom, $user)) {
            throw new AuthorizationException('You can only copy permissions from your own tokens.');
        }

        return DB::transaction(function () use ($user, $name, $copyFrom) {
            $new = $user->createToken($name, [self::ABILITY]);
            $tokenId = $new->accessToken->getKey();
            $now = now();

            $rows = $copyFrom === null
                ? array_map(fn (Capability $capability) => [
                    'capability' => $capability->value,
                    'scope_type' => ScopeType::Global->value,
                    'organization' => null,
                    'server_id' => null,
                    'site_id' => null,
                    'allowed' => true,
                ], array_values(array_filter(Capability::cases(), fn (Capability $c) => $c->defaultAllowed())))
                : PermissionRule::query()
                    ->where('personal_access_token_id', $copyFrom->getKey())
                    ->orderBy('id')
                    ->toBase()
                    ->get(['capability', 'scope_type', 'organization', 'server_id', 'site_id', 'allowed'])
                    ->map(fn (object $row) => (array) $row)
                    ->all();

            if ($rows !== []) {
                PermissionRule::query()->insert(array_map(
                    fn (array $row) => ['personal_access_token_id' => $tokenId, 'created_at' => $now, 'updated_at' => $now] + $row,
                    $rows,
                ));
            }

            $this->engine->flush();

            return $new;
        });
    }

    private function ownedBy(PersonalAccessToken $token, User $user): bool
    {
        return $token->tokenable_type === $user->getMorphClass()
            && (int) $token->tokenable_id === (int) $user->getKey();
    }
}
