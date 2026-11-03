<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\OutputTail;
use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read the output log of one deployment of a Forge site (last lines only). Secrets are redacted.')]
#[IsReadOnly]
class GetDeploymentLog extends ForgeTool
{
    protected string $name = 'get_deployment_log';

    protected function capability(): Capability
    {
        return Capability::ViewLogs;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id the site lives on. Defaults to the configured server if omitted.'),
            'site_id' => $schema->integer()->description('Forge site id (from list_sites). Defaults to the configured site if omitted.'),
            'deployment_id' => $schema->integer()->required()->description('Deployment id (from list_deployments).'),
            'lines' => $schema->integer()->description('Number of trailing lines to return.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $siteId = $this->resolveSiteId($request, $org, $serverId);
        $deploymentId = (int) $request->get('deployment_id');
        $lines = $request->get('lines') !== null ? (int) $request->get('lines') : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $deploymentId, $lines) {
                $log = $ctx->forge->deploymentLog($org, $serverId, $siteId, $deploymentId);
                $ctx->summary = "Read log of deployment {$deploymentId} on site {$siteId}.";

                return $log === '' ? 'The deployment log is empty.' : OutputTail::tail($log, $lines);
            },
        );
    }
}
