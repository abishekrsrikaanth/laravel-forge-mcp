<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Tools\ForgeTool;
use App\Permissions\Capability;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/** A non-read tool: hidden from fresh tokens. Never calls Forge. */
class DeployProbe extends ForgeTool
{
    protected string $name = 'deploy_probe';

    public static int $runs = 0;

    protected function capability(): Capability
    {
        return Capability::Deploy;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['organization' => $schema->string(), 'server_id' => $schema->integer()];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);

        return $this->guarded($request, new Target($org, (int) $request->get('server_id', 1)), function () {
            self::$runs++;

            return 'deployed';
        }, scrub: false);
    }
}
