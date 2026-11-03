<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List Forge sites: id, name, server, PHP version and deployment status. Pass server_id to list one server, otherwise all sites in the organization.')]
#[IsReadOnly]
class ListSites extends ForgeTool
{
    protected string $name = 'list_sites';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Only list sites on this server.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $request->get('server_id') !== null ? (int) $request->get('server_id') : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $serverId !== null ? $this->serverTarget($ctx, $org, $serverId) : new Target($org),
            function (ToolContext $ctx) use ($org, $serverId) {
                $engine = app(PermissionEngine::class);
                $records = $serverId !== null ? $ctx->forge->sites($org, $serverId) : $ctx->forge->organizationSites($org);
                $sites = [];

                foreach ($records as $site) {
                    $siteId = (int) ($site['id'] ?? 0);
                    // The server-scoped list may omit server_id; the requested server is then authoritative.
                    $site['server_id'] = (int) ($site['server_id'] ?? $serverId ?? 0);

                    // No server id means no safe target: fail closed.
                    if ($siteId > 0 && $site['server_id'] > 0
                        && $engine->allows($ctx->token, Capability::View, new Target($org, $site['server_id'], $siteId))) {
                        $sites[] = $site;
                    }
                }

                usort($sites, fn (array $a, array $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

                $ctx->summary = 'Listed '.count($sites).' '.(count($sites) === 1 ? 'site' : 'sites')." in {$org}.";

                if ($sites === []) {
                    return 'No sites visible to this access token.';
                }

                return implode("\n", array_map(fn (array $s) => sprintf(
                    '%d %s server:%d php:%s deploy:%s',
                    $s['id'],
                    $s['name'] ?? '-',
                    $s['server_id'],
                    $s['php_version'] ?? '-',
                    $s['deployment_status'] ?? '-',
                ), $sites));
            },
        );
    }

    /** Listing is filtered per site, so it only needs View somewhere. */
    protected function permits(ToolContext $ctx, Target $target): bool
    {
        return app(PermissionEngine::class)->allowsAnywhere($ctx->token, $this->capability());
    }
}
