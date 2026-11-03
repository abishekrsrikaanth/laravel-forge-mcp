<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\ForgeTool;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/** Resolves a site target through ForgeTool::siteTarget(). */
class SiteProbe extends ForgeTool
{
    protected string $name = 'site_probe';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['organization' => $schema->string(), 'server_id' => $schema->integer(), 'site_id' => $schema->integer()];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteId = (int) $request->get('site_id');

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            fn () => "site {$siteId} ok",
            scrub: false,
        );
    }
}
