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

#[Description('Restart Nginx on a Forge server. Briefly interrupts web serving on every site on that server.')]
#[IsDestructive]
class RestartNginx extends ForgeTool
{
    protected string $name = 'restart_nginx';

    protected function capability(): Capability
    {
        return Capability::RestartServices;
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

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId) {
                $ctx->forge->nginxAction($org, $serverId, 'reboot');
                $ctx->summary = "Restarted nginx on server {$serverId}";

                return "Nginx restart queued on server {$this->serverName($ctx, $org, $serverId)}.";
            },
        );
    }
}
