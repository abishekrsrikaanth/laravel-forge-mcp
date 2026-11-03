<?php

namespace Tests\Fixtures\Mcp;

use App\Forge\ForgeException;
use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\ForgeTool;
use App\Permissions\Capability;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use RuntimeException;

/** Tries to leak the Forge API token through every exit of guarded(). */
class LeakProbe extends ForgeTool
{
    protected string $name = 'leak_probe';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['organization' => $schema->string(), 'mode' => $schema->string()];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $mode = (string) $request->get('mode', 'return');

        return $this->guarded($request, new Target($org), function (ToolContext $ctx) use ($mode) {
            $token = $ctx->connection->token;
            $ctx->summary = "summary mentions {$token}";

            return match ($mode) {
                'forge' => throw new ForgeException("Forge echoed {$token}", 500),
                'crash' => throw new RuntimeException("Crashed holding {$token}"),
                'raw' => "raw output {$token}",
                default => "output {$token}",
            };
        }, scrub: $mode !== 'raw');
    }
}
