<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

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

#[Description('List the Forge servers in an organization: id, name, IP address, PHP version and connection status.')]
#[IsReadOnly]
class ListServers extends ForgeTool
{
    use FiltersItemsByPermission;

    protected string $name = 'list_servers';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);

        return $this->guarded($request, new Target($org), function (ToolContext $ctx) use ($org) {
            $engine = app(PermissionEngine::class);
            $servers = [];

            foreach ($ctx->forge->servers($org) as $server) {
                $id = (int) ($server['id'] ?? 0);

                if ($id > 0 && $engine->allows($ctx->token, Capability::View, new Target($org, $id))) {
                    $servers[] = $server;
                }
            }

            usort($servers, fn (array $a, array $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

            $ctx->summary = 'Listed '.count($servers).' '.(count($servers) === 1 ? 'server' : 'servers')." in {$org}.";

            if ($servers === []) {
                return 'No servers visible to this access token.';
            }

            return implode("\n", array_map(fn (array $s) => sprintf(
                '%d %s %s php:%s %s',
                $s['id'],
                $s['name'] ?? '-',
                $s['ip_address'] ?? '-',
                $s['php_version'] ?? '-',
                $s['connection_status'] ?? '-',
            ), $servers));
        });
    }
}
