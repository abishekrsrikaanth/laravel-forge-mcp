<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Forge\ForgeException;
use App\Mcp\Support\OutputTail;
use App\Mcp\Support\Poller;
use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolError;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Deploy a Forge site and wait briefly for the result. Returns the final status and the last lines of the deployment log, or a hint to poll get_deployment_status if it is still running.')]
class DeploySite extends ForgeTool
{
    private const FINISHED = ['finished', 'failed', 'failed-build', 'cancelled'];

    private const LOG_LINES = 50;

    protected string $name = 'deploy_site';

    protected function capability(): Capability
    {
        return Capability::Deploy;
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
                $deployment = $ctx->forge->deploy($org, $serverId, $siteId);
                $deploymentId = (int) ($deployment['id'] ?? 0);

                if ($deploymentId === 0) {
                    throw new ToolError('Forge accepted the deployment but returned no deployment id. Call list_deployments to find it.');
                }

                // Once the deployment has started, a failure must still tell the agent its id (no blind redeploy).
                // The audit row is `success` for the trigger; the failure is only reported in the output.
                try {
                    $poll = Poller::until(
                        fn () => $ctx->forge->deployment($org, $serverId, $siteId, $deploymentId),
                        fn (array $d) => in_array($d['status'] ?? null, self::FINISHED, true),
                    );

                    $status = $poll['result']['status'] ?? null;
                    $ctx->summary = "Deployed site {$siteId}: ".($status ?? 'pending');

                    if (! $poll['done']) {
                        return "Deployment {$deploymentId} is still ".($status ?? 'pending').'. Call get_deployment_status to check progress.';
                    }

                    $log = $ctx->forge->deploymentLog($org, $serverId, $siteId, $deploymentId);
                } catch (ForgeException $e) {
                    $ctx->summary = "Deployed site {$siteId}: started, progress check failed";

                    return "Deployment {$deploymentId} was started, but checking its progress failed: {$e->agentMessage()}. Call get_deployment_status or get_deployment_log.";
                }

                return "Deployment {$deploymentId} {$status}.\n".($log === '' ? 'The deployment log is empty.' : OutputTail::tail($log, self::LOG_LINES));
            },
        );
    }
}
