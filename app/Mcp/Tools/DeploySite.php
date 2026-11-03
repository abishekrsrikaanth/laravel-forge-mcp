<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\OutputTail;
use App\Mcp\Support\Poller;
use App\Mcp\Support\ToolContext;
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
                $deploymentId = $deployment['id'] ?? null;

                $poll = Poller::until(
                    fn () => $ctx->forge->deploymentStatus($org, $serverId, $siteId),
                    fn (array $status) => in_array($status['status'] ?? null, self::FINISHED, true),
                );

                $status = $poll['result']['status'] ?? null;

                if (! $poll['done']) {
                    $ctx->summary = "Deployed site {$siteId}: ".($status ?? 'pending');

                    return 'Deployment '.($deploymentId ?? '-').' is still '.($status ?? 'pending').'. Call get_deployment_status to check progress.';
                }

                $ctx->summary = "Deployed site {$siteId}: {$status}";
                $log = $deploymentId !== null
                    ? $ctx->forge->deploymentLog($org, $serverId, $siteId, (int) $deploymentId)
                    : '';

                return "Deployment {$deploymentId} {$status}.\n".($log === '' ? 'The deployment log is empty.' : OutputTail::tail($log, self::LOG_LINES));
            },
        );
    }
}
