<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\Concerns\FormatsCommandResult;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get the status, exit code and output of a command started with run_site_command. Secrets are redacted.')]
#[IsReadOnly]
class GetCommandResult extends ForgeTool
{
    use FormatsCommandResult;

    protected string $name = 'get_command_result';

    protected function capability(): Capability
    {
        return Capability::ViewLogs;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id the site lives on.'),
            'site_id' => $schema->integer()->required()->description('Forge site id (from list_sites).'),
            'command_id' => $schema->integer()->required()->description('Command id returned by run_site_command.'),
            'lines' => $schema->integer()->description('Number of trailing output lines to return.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteId = (int) $request->get('site_id');
        $commandId = (int) $request->get('command_id');
        $lines = $request->get('lines') !== null ? (int) $request->get('lines') : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $commandId, $lines) {
                // The Forge path is site-scoped, so a command of another site is a 404.
                $command = $ctx->forge->command($org, $serverId, $siteId, $commandId);
                $ctx->summary = "Read result of command {$commandId} on site {$siteId}.";

                return $this->commandReport($ctx, $org, $serverId, $siteId, $commandId, $command, $lines);
            },
        );
    }
}
