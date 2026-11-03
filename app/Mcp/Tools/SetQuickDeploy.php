<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\NamesSites;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Enable or disable quick deploy (push to deploy) for a Forge site. When enabled, a push to the site repository triggers a deployment.')]
class SetQuickDeploy extends ForgeTool
{
    use NamesSites;

    protected string $name = 'set_quick_deploy';

    protected function capability(): Capability
    {
        return Capability::ConfigureDeployments;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id the site lives on.'),
            'site_id' => $schema->integer()->required()->description('Forge site id (from list_sites).'),
            'enabled' => $schema->boolean()->required()->description('true to enable quick deploy, false to disable it.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $enabled = $request->get('enabled');

        if (! is_bool($enabled)) {
            throw new ToolError('enabled must be true or false.');
        }

        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteId = (int) $request->get('site_id');

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $enabled) {
                $ctx->forge->setPushToDeploy($org, $serverId, $siteId, $enabled);
                $state = $enabled ? 'enabled' : 'disabled';
                $ctx->summary = "Quick deploy {$state} for site {$siteId}";

                return "Quick deploy {$state} for {$this->siteLabel($ctx, $org, $serverId, $siteId)}.";
            },
        );
    }
}
