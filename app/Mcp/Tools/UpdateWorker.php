<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\Concerns\RejectsControlCharacters;
use App\Mcp\Tools\Concerns\TargetsExistingWorker;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Rename a background process (queue worker or daemon) and optionally replace its raw supervisor config. A wrong config can break the process; the config is recorded in the audit log.')]
#[IsDestructive]
class UpdateWorker extends ForgeTool
{
    use RejectsControlCharacters;
    use TargetsExistingWorker;

    protected string $name = 'update_worker';

    protected function capability(): Capability
    {
        return Capability::ConfigureWorkers;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id.'),
            'worker_id' => $schema->integer()->required()->description('Background process id (from list_workers).'),
            'name' => $schema->string()->required()->description('New name for the process.'),
            'config' => $schema->string()->description('Raw supervisor configuration to replace the current one (needs the permission on the whole server). Omit to keep it.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate(['name' => ['required', 'string']]);

        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $workerId = (int) $request->get('worker_id');
        $name = (string) $request->get('name');
        $config = $request->get('config') !== null ? (string) $request->get('config') : null;

        $this->assertSingleLine('name', $name);
        if ($config !== null) {
            $this->assertNoNul('config', $config);
        }

        return $this->guarded(
            $request,
            function (ToolContext $ctx) use ($org, $serverId, $workerId, $config) {
                $target = ($this->existingWorkerTarget($org, $serverId, $workerId))($ctx);

                // Raw supervisor config can set any user= and command=, so it needs the server grant.
                return $config !== null
                    ? $this->requireServerPermission($ctx, $target, 'Replacing a worker\'s raw supervisor config')
                    : $target;
            },
            function (ToolContext $ctx) use ($org, $serverId, $workerId, $name, $config) {
                $ctx->forge->updateBackgroundProcess($org, $serverId, $workerId, $name, $config);
                $ctx->summary = "Updated worker {$workerId} on server {$serverId}".($config !== null ? ' (supervisor config replaced).' : '.');

                return "Worker {$workerId} updated on server {$this->serverName($ctx, $org, $serverId)}.";
            },
        );
    }
}
