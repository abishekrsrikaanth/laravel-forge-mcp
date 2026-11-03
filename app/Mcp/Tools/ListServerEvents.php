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

#[Description('List recent events of a Forge server: id, time, description and the user it ran as.')]
#[IsReadOnly]
class ListServerEvents extends ForgeTool
{
    protected string $name = 'list_server_events';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id. Defaults to the configured server if omitted.'),
            'limit' => $schema->integer()->description('Number of events to return (default 20, max 50).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $limit = min(50, max(1, (int) ($request->get('limit') ?? 20)));

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->serverWideTarget($ctx, $org, $serverId, Capability::View),
            function (ToolContext $ctx) use ($org, $serverId, $limit) {
                $events = array_slice($ctx->forge->events($org, $serverId, $limit), 0, $limit);
                $ctx->summary = "Listed events of server {$serverId}.";

                if ($events === []) {
                    return 'No events found.';
                }

                return implode("\n", array_map(static function (array $e): string {
                    $show = static fn (mixed $v): string => is_scalar($v) && $v !== '' ? (string) $v : '-';

                    return $show($e['id'] ?? null).' '.$show($e['created_at'] ?? null).' '.$show($e['description'] ?? null).' ('.$show($e['ran_as'] ?? null).')';
                }, $events));
            },
        );
    }
}
