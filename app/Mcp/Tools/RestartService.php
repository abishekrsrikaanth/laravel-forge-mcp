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

#[Description('Restart Redis, Supervisor, MySQL or Postgres on a Forge server. Briefly interrupts that service for everything on the server. Use restart_nginx and restart_php_fpm for those services.')]
#[IsDestructive]
class RestartService extends ForgeTool
{
    private const array LABELS = ['redis' => 'Redis', 'supervisor' => 'Supervisor', 'mysql' => 'MySQL', 'postgres' => 'Postgres'];

    protected string $name = 'restart_service';

    protected function capability(): Capability
    {
        return Capability::RestartServices;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id (from list_servers).'),
            'service' => $schema->string()->enum(array_keys(self::LABELS))->required()->description('Service to restart.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate(['service' => ['required', 'string', 'in:'.implode(',', array_keys(self::LABELS))]]);

        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $service = (string) $request->get('service');

        // Always the server: restarting a service is server-wide, so site-level rules never apply.
        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId, $service) {
                $ctx->forge->serviceAction($org, $serverId, $service, 'reboot');
                $label = self::LABELS[$service];
                $ctx->summary = "Restarted {$service} on server {$serverId}";

                return "{$label} restart queued on server {$this->serverName($ctx, $org, $serverId)}.";
            },
        );
    }
}
