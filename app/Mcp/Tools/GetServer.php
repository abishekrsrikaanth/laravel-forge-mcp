<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Show details of one Forge server: name, IP address, provider, region, PHP and Ubuntu versions, database type, readiness.')]
#[IsReadOnly]
class GetServer extends ForgeTool
{
    protected string $name = 'get_server';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id (from list_servers). Defaults to the configured server if omitted.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId) {
                $server = $ctx->forge->server($org, $serverId);
                $ctx->summary = "Fetched server {$serverId}.";

                $ready = $server['is_ready'] ?? null;

                return implode("\n", [
                    'Server '.$serverId,
                    'name: '.($server['name'] ?? '-'),
                    'ip_address: '.($server['ip_address'] ?? '-'),
                    'provider: '.($server['provider'] ?? '-'),
                    'region: '.($server['region'] ?? '-'),
                    'php_version: '.($server['php_version'] ?? '-'),
                    'ubuntu_version: '.($server['ubuntu_version'] ?? '-'),
                    'database_type: '.($server['database_type'] ?? '-'),
                    'is_ready: '.($ready === null ? '-' : ($ready ? 'yes' : 'no')),
                ]);
            },
        );
    }
}
