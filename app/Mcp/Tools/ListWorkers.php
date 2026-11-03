<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Forge\WorkerSiteResolver;
use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\Concerns\FiltersItemsByPermission;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the background processes (queue workers, daemons) on a Forge server: id, status, process count, user, command and the site each belongs to. Pass site_id to list one site.')]
#[IsReadOnly]
class ListWorkers extends ForgeTool
{
    use FiltersItemsByPermission;

    protected string $name = 'list_workers';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id.'),
            'site_id' => $schema->integer()->description('Only list workers belonging to this site.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteFilter = $request->get('site_id') !== null ? (int) $request->get('site_id') : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId, $siteFilter) {
                $engine = app(PermissionEngine::class);
                $rows = [];

                foreach ($ctx->forge->backgroundProcesses($org, $serverId) as $worker) {
                    $id = (int) ($worker['id'] ?? 0);

                    if ($id <= 0) {
                        continue;
                    }

                    $siteId = WorkerSiteResolver::siteIdFor($ctx->forge, $org, $serverId, $worker);

                    if ($siteFilter !== null && $siteId !== $siteFilter) {
                        continue;
                    }

                    // A worker with no resolved site is governed by server-level rules only.
                    $target = $siteId !== null ? new Target($org, $serverId, $siteId) : new Target($org, $serverId);

                    if ($engine->allows($ctx->token, Capability::View, $target)) {
                        $rows[] = sprintf(
                            '%d %s x%s %s %s site:%s',
                            $id,
                            $worker['status'] ?? '-',
                            $worker['processes'] ?? '-',
                            $worker['user'] ?? '-',
                            $worker['command'] ?? '-',
                            $siteId ?? '-',
                        );
                    }
                }

                $ctx->summary = 'Listed '.count($rows).' '.(count($rows) === 1 ? 'worker' : 'workers')." on server {$serverId}.";

                return $rows === [] ? 'No workers visible to this access token.' : implode("\n", $rows);
            },
        );
    }
}
