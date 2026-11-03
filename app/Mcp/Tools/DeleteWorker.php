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

#[Description('Delete a background process (queue worker or daemon) from a Forge server. This stops it and removes its configuration.')]
#[IsDestructive]
class DeleteWorker extends ForgeTool
{
    use TargetsExistingWorker;

    protected string $name = 'delete_worker';

    protected function capability(): Capability
    {
        return Capability::ConfigureWorkers;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id. Defaults to the configured server if omitted.'),
            'worker_id' => $schema->integer()->required()->description('Background process id (from list_workers).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $workerId = (int) $request->get('worker_id');

        return $this->guarded(
            $request,
            $this->existingWorkerTarget($org, $serverId, $workerId),
            function (ToolContext $ctx) use ($org, $serverId, $workerId) {
                $ctx->forge->deleteBackgroundProcess($org, $serverId, $workerId);
                $ctx->summary = "Deleted worker {$workerId} from server {$serverId}.";

                return "Worker {$workerId} deleted from server {$this->serverName($ctx, $org, $serverId)}.";
            },
        );
    }
}
