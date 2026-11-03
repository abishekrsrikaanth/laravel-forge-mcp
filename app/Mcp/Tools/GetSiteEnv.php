<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use App\Support\EnvFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The only tool whose output is not scrubbed: the user opted in to view_env, so the .env is shown
 * as-is (only the Forge API token itself is replaced). The audit row records the key count only.
 */
#[Description('Show the full .env file of a Forge site, including secret values. Do not repeat secret values back to the user unless they ask for them.')]
#[IsReadOnly]
class GetSiteEnv extends ForgeTool
{
    protected string $name = 'get_site_env';

    protected function capability(): Capability
    {
        return Capability::ViewEnv;
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
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                $contents = $ctx->forge->environment($org, $serverId, $siteId);
                $ctx->summary = 'Read .env ('.count(EnvFile::parse($contents)).' keys)';

                return $contents === '' ? "The .env file of site {$siteId} is empty." : $contents;
            },
            scrub: false,
        );
    }
}
