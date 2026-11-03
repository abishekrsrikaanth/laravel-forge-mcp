<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\ToolContext;

trait NamesSites
{
    /** The site's domain from the cached site list, else "site {id}". */
    protected function siteLabel(ToolContext $ctx, string $org, int $serverId, int $siteId): string
    {
        foreach ($ctx->forge->sites($org, $serverId) as $site) {
            if ((int) ($site['id'] ?? 0) === $siteId && is_string($site['name'] ?? null) && $site['name'] !== '') {
                return $site['name'];
            }
        }

        return "site {$siteId}";
    }
}
