<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\OutputTail;
use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read the log of a background process (queue worker or daemon) on a Forge server (last lines only). Secrets are redacted.')]
#[IsReadOnly]
class GetWorkerLog extends ForgeTool
{
    protected string $name = 'get_worker_log';

    protected function capability(): Capability
    {
        return Capability::ViewLogs;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id. Defaults to the configured server if omitted.'),
            'worker_id' => $schema->integer()->required()->description('Background process id (from list_workers).'),
            'lines' => $schema->integer()->description('Number of trailing lines to return.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $workerId = (int) $request->get('worker_id');
        $lines = $request->get('lines') !== null ? (int) $request->get('lines') : null;

        return $this->guarded(
            $request,
            function (ToolContext $ctx) use ($org, $serverId, $workerId): Target {
                $this->serverTarget($ctx, $org, $serverId);

                $worker = $ctx->forge->backgroundProcess($org, $serverId, $workerId);

                return $this->workerTarget($ctx, $org, $serverId, $worker, $this->capability());
            },
            function (ToolContext $ctx) use ($org, $serverId, $workerId, $lines) {
                $log = $ctx->forge->backgroundProcessLog($org, $serverId, $workerId);
                $ctx->summary = "Read log of worker {$workerId} on server {$serverId}.";

                return $log === '' ? 'The worker log is empty.' : OutputTail::tail($log, $lines);
            },
        );
    }
}
