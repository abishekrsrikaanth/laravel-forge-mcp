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

#[Description('Read the deployment script of a Forge site. Secrets found in the script are replaced with [REDACTED] markers.')]
#[IsReadOnly]
class GetDeploymentScript extends ForgeTool
{
    protected string $name = 'get_deployment_script';

    protected function capability(): Capability
    {
        return Capability::View;
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
                $script = $ctx->forge->deploymentScript($org, $serverId, $siteId);
                $ctx->summary = "Read deployment script of site {$siteId}";

                return $script === '' ? 'The deployment script is empty.' : $script;
            },
        );
    }
}
