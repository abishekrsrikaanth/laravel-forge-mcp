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

#[Description('Show details of one Forge site: name, URL, repository, PHP version, deployment status, quick deploy and directories.')]
#[IsReadOnly]
class GetSite extends ForgeTool
{
    protected string $name = 'get_site';

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
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                $this->serverTarget($ctx, $org, $serverId);

                return $this->siteTarget($ctx, $org, $serverId, $siteId);
            },
            function (ToolContext $ctx) use ($org, $siteId) {
                $site = $ctx->forge->site($org, $siteId);
                $ctx->summary = "Fetched site {$siteId}.";

                $quick = $site['quick_deploy'] ?? null;

                return implode("\n", [
                    'Site '.$siteId,
                    'name: '.($site['name'] ?? '-'),
                    'url: '.($site['url'] ?? '-'),
                    'repository: '.($site['repository'] ?? '-'),
                    'php_version: '.($site['php_version'] ?? '-'),
                    'deployment_status: '.($site['deployment_status'] ?? '-'),
                    'quick_deploy: '.($quick === null ? '-' : ($quick ? 'on' : 'off')),
                    'root_directory: '.($site['root_directory'] ?? '-'),
                    'web_directory: '.($site['web_directory'] ?? '-'),
                ]);
            },
        );
    }
}
