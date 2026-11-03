<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use App\Permissions\PermissionEngine;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the Forge organizations this MCP server can access. Use a slug as the `organization` argument of other tools.')]
#[IsReadOnly]
class ListOrganizations extends ForgeTool
{
    protected string $name = 'list_organizations';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    /** Also shown when Forge is not connected or nothing is exposed, so the agent learns why. */
    public function shouldRegister(Request $request): bool
    {
        if (parent::shouldRegister($request)) {
            return true;
        }

        $token = $this->accessToken($request);

        return $token !== null && $token->can('mcp') && ($this->connection($request)?->exposedOrganizations() ?? []) === [];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Only show this organization (slug).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $connection = $this->connection($request);

        if ($connection !== null && $connection->exposedOrganizations() === []) {
            return Response::error('No organizations are exposed to this MCP server. The user can expose one in the Forge MCP dashboard.');
        }

        $only = $connection !== null && $request->get('organization') !== null
            ? $this->resolveOrganization($request)
            : null;

        return $this->guarded($request, new Target($only), function (ToolContext $ctx) use ($only) {
            $engine = app(PermissionEngine::class);
            $lines = [];

            foreach ($ctx->forge->organizations() as $org) {
                $slug = (string) ($org['slug'] ?? '');

                if (! $ctx->connection->exposes($slug)
                    || ($only !== null && $slug !== $only)
                    || ! $engine->allows($ctx->token, Capability::View, new Target($slug))) {
                    continue;
                }

                $lines[] = "- {$slug}: ".(string) ($org['name'] ?? $slug);
            }

            $count = count($lines);
            $ctx->summary = 'Listed '.$count.' '.($count === 1 ? 'organization' : 'organizations').'.';

            if ($lines === []) {
                return 'No organizations are available to this access token.';
            }

            return "Organizations ({$count}):\n".implode("\n", $lines)."\n\nPass a slug as `organization` to list_servers and other tools.";
        });
    }

    /** Listing is filtered per organization, so it only needs View somewhere when no organization is given. */
    protected function permits(ToolContext $ctx, Target $target): bool
    {
        return $target->organization === null
            ? app(PermissionEngine::class)->allowsAnywhere($ctx->token, $this->capability())
            : parent::permits($ctx, $target);
    }
}
