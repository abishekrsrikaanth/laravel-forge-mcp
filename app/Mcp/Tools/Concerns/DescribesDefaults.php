<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Forge\ForgeException;
use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\Target;

/** Formats default organization/server/site values for tool output, naming ids from the cached Forge lists only. */
trait DescribesDefaults
{
    protected function sourceLabel(?string $source): string
    {
        return match ($source) {
            'header' => 'from project headers',
            'token' => 'from this access token',
            default => 'none',
        };
    }

    /**
     * "{org} / server {name} ({id}) / site {name} ({id})". Names come from cached lists and fall back to
     * bare ids when the organization is unknown or unexposed, or when Forge cannot be reached.
     */
    protected function describeDefaults(ToolContext $ctx, ?string $organization, ?int $serverId, ?int $siteId): string
    {
        $org = $organization;
        if ($org === null) {
            $exposed = $ctx->connection->exposedOrganizations();
            $org = count($exposed) === 1 ? $exposed[0] : null;
        }
        $canName = $org !== null && $ctx->connection->exposes($org);
        $engine = app(PermissionEngine::class);

        $parts = [];
        if ($organization !== null) {
            $parts[] = $organization;
        }

        if ($serverId !== null) {
            // Names only for targets the token may View; defaults can be client-chosen or stale.
            $name = $canName && $engine->allows($ctx->token, Capability::View, new Target($org, $serverId))
                ? $this->cachedName(fn () => $ctx->forge->servers($org), $serverId) : null;
            $parts[] = 'server '.($name !== null ? "{$name} ({$serverId})" : $serverId);
        }

        if ($siteId !== null && $serverId !== null) {
            $name = $canName && $engine->allows($ctx->token, Capability::View, new Target($org, $serverId, $siteId))
                ? $this->cachedName(fn () => $ctx->forge->sites($org, $serverId), $siteId) : null;
            $parts[] = 'site '.($name !== null ? "{$name} ({$siteId})" : $siteId);
        }

        return implode(' / ', $parts);
    }

    /** @param  callable(): array<int, array<string, mixed>>  $list */
    private function cachedName(callable $list, int $id): ?string
    {
        try {
            foreach ($list() as $record) {
                if ((int) ($record['id'] ?? 0) === $id && is_string($record['name'] ?? null) && $record['name'] !== '') {
                    return $record['name'];
                }
            }
        } catch (ForgeException) {
            // Fall back to the bare id.
        }

        return null;
    }
}
