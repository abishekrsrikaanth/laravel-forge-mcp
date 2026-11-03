<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Reboot a Forge server. Every site and service on it is unavailable until it is back up.')]
#[IsDestructive]
class RebootServer extends ForgeTool
{
    protected string $name = 'reboot_server';

    protected function capability(): Capability
    {
        return Capability::RebootServer;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id (from list_servers).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');

        // Always a server-level target: site rules never apply to this capability.
        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId) {
                $ctx->forge->serverAction($org, $serverId, 'reboot');
                $ctx->summary = "Rebooted server {$serverId}";

                return "Reboot queued for server {$this->serverName($ctx, $org, $serverId)}.";
            },
        );
    }
}
