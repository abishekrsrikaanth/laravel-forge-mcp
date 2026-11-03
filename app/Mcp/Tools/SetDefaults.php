<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolDenied;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\DescribesDefaults;
use App\Models\TokenDefault;
use App\Permissions\Capability;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Store a default organization, server and (optionally) site for this access token, used when later calls omit them. Project .mcp.json headers override these. Replaces any previously stored defaults.')]
class SetDefaults extends ForgeTool
{
    use DescribesDefaults;

    protected string $name = 'set_defaults';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id (from list_servers).')->required(),
            'site_id' => $schema->integer()->description('Optional Forge site id on that server (from list_sites).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        // Explicit arguments only: defaults are never used to fill in what is being set.
        $org = $this->explicitOrganization($request);

        $serverId = $request->get('server_id');
        if ($serverId === null || $serverId === '') {
            throw new ToolError('server_id is required.');
        }
        $serverId = (int) $serverId;

        $siteArgument = $request->get('site_id');
        $siteId = $siteArgument !== null && $siteArgument !== '' ? (int) $siteArgument : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $siteId !== null
                ? $this->siteTarget($ctx, $org, $serverId, $siteId)
                : $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                TokenDefault::query()->updateOrCreate(
                    ['personal_access_token_id' => $ctx->token->getKey()],
                    ['organization' => $org, 'server_id' => $serverId, 'site_id' => $siteId],
                );

                $where = $this->describeDefaults($ctx, $org, $serverId, $siteId);
                $ctx->summary = "Set token defaults to {$where}.";

                // Not "token: {$where}": the output scrubber masks the value of a `token: value` assignment.
                return "Defaults saved for this access token ({$where}).";
            },
        );
    }

    /** The `organization` argument, or the only exposed organization; never a default. */
    private function explicitOrganization(Request $request): string
    {
        $exposed = $this->connection($request)?->exposedOrganizations();
        if ($exposed === null) {
            throw new ToolError(self::NOT_CONNECTED);
        }

        $org = $request->get('organization');

        if ($org === null || $org === '') {
            return match (count($exposed)) {
                0 => throw new ToolError('No organizations are exposed to this MCP server. The user can expose one in the Forge MCP dashboard.'),
                1 => $exposed[0],
                default => throw new ToolError('Several organizations are available ('.implode(', ', $exposed).'). Pass the `organization` argument.'),
            };
        }

        if (! is_string($org) || ! in_array($org, $exposed, true)) {
            $shown = is_scalar($org) ? (string) $org : get_debug_type($org);

            throw new ToolDenied("Organization '{$shown}' is not available to this MCP server.", is_string($org) ? new Target($org) : null);
        }

        return $org;
    }
}
