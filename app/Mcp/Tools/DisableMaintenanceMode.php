<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\Concerns\NamesSites;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Take a Forge site out of Laravel maintenance mode. The change is applied asynchronously; check get_maintenance_mode.')]
class DisableMaintenanceMode extends ForgeTool
{
    use NamesSites;

    protected string $name = 'disable_maintenance_mode';

    protected function capability(): Capability
    {
        return Capability::MaintenanceMode;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id the site lives on.'),
            'site_id' => $schema->integer()->required()->description('Forge site id (from list_sites).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteId = (int) $request->get('site_id');

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                $ctx->forge->disableMaintenance($org, $serverId, $siteId);
                $ctx->summary = "Disabled maintenance mode for site {$siteId}";

                return "Maintenance mode is being disabled for {$this->siteLabel($ctx, $org, $serverId, $siteId)}.";
            },
        );
    }
}
