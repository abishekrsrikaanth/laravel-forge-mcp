<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\OutputTail;
use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read the output of a Forge server event (last lines only). Secrets of the server sites are redacted.')]
#[IsReadOnly]
class GetServerEventOutput extends ForgeTool
{
    protected string $name = 'get_server_event_output';

    protected function capability(): Capability
    {
        return Capability::ViewLogs;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id.'),
            'event_id' => $schema->integer()->required()->description('Event id (from list_server_events).'),
            'lines' => $schema->integer()->description('Number of trailing lines to return.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $eventId = (int) $request->get('event_id');
        $lines = $request->get('lines') !== null ? (int) $request->get('lines') : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId, $eventId, $lines) {
                $output = $ctx->forge->eventOutput($org, $serverId, $eventId);
                $ctx->summary = "Read output of event {$eventId} on server {$serverId}.";

                return $output === '' ? 'The event has no output.' : OutputTail::tail($output, $lines);
            },
        );
    }
}
