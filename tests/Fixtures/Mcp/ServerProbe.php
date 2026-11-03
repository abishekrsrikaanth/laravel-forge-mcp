<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\ForgeTool;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/** Resolves a server target through ForgeTool::serverTarget(). */
class ServerProbe extends ForgeTool
{
    protected string $name = 'server_probe';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['organization' => $schema->string(), 'server_id' => $schema->integer()];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->serverTarget($ctx, $org, $serverId),
            fn () => "server {$serverId} ok",
            scrub: false,
        );
    }
}
