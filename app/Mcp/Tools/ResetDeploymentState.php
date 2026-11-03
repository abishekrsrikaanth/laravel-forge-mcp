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

#[Description('Reset the deployment state of a Forge site that is stuck in "deploying", so a new deployment can be started.')]
class ResetDeploymentState extends ForgeTool
{
    use NamesSites;

    protected string $name = 'reset_deployment_state';

    protected function capability(): Capability
    {
        return Capability::Deploy;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id the site lives on. Defaults to the configured server if omitted.'),
            'site_id' => $schema->integer()->description('Forge site id (from list_sites). Defaults to the configured site if omitted.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $siteId = $this->resolveSiteId($request, $org, $serverId);

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                $ctx->forge->resetDeploymentStatus($org, $serverId, $siteId);
                $ctx->summary = "Reset deployment state of site {$siteId}";

                return "Deployment state reset for {$this->siteLabel($ctx, $org, $serverId, $siteId)}.";
            },
        );
    }
}
