<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\Concerns\TargetsExistingWorker;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Stop a background process (queue worker or daemon) on a Forge server. It stays stopped until started again.')]
#[IsDestructive]
class StopWorker extends ForgeTool
{
    use TargetsExistingWorker;

    protected string $name = 'stop_worker';

    protected function capability(): Capability
    {
        return Capability::ManageWorkers;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id.'),
            'worker_id' => $schema->integer()->required()->description('Background process id (from list_workers).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $workerId = (int) $request->get('worker_id');

        return $this->guarded(
            $request,
            $this->existingWorkerTarget($org, $serverId, $workerId),
            function (ToolContext $ctx) use ($org, $serverId, $workerId) {
                $ctx->forge->backgroundProcessAction($org, $serverId, $workerId, 'stop');
                $ctx->summary = "Stop worker {$workerId} on server {$serverId}.";

                return "Stop queued for worker {$workerId} on server {$this->serverName($ctx, $org, $serverId)}.";
            },
        );
    }
}
