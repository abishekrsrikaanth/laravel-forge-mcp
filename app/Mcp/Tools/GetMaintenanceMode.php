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

#[Description('Show whether Laravel maintenance mode is on for a Forge site, and whether it is being enabled or disabled.')]
#[IsReadOnly]
class GetMaintenanceMode extends ForgeTool
{
    protected string $name = 'get_maintenance_mode';

    protected function capability(): Capability
    {
        return Capability::View;
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
                $integration = $ctx->forge->integration($org, $serverId, $siteId, 'laravel-maintenance');
                $status = $integration['status'] ?? null;
                $ctx->summary = "Read maintenance mode of site {$siteId}";

                return 'Maintenance mode: '.(($integration['enabled'] ?? false) === true ? 'on' : 'off')
                    .(in_array($status, ['enabling', 'disabling'], true) ? " (status: {$status})" : '');
            },
        );
    }
}
