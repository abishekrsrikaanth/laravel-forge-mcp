<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create a background process (queue worker or daemon) on a Forge server, optionally for a site. Runs as the forge user unless user=root is given. This executes an arbitrary command on the server.')]
#[IsDestructive]
class CreateWorker extends ForgeTool
{
    protected string $name = 'create_worker';

    protected function capability(): Capability
    {
        return Capability::ConfigureWorkers;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id.'),
            'command' => $schema->string()->required()->description('Command to run, e.g. "php artisan queue:work --tries=3".'),
            'site_id' => $schema->integer()->description('Site the process belongs to. Permission is then checked on that site.'),
            'name' => $schema->string()->description('Process name. Defaults to the command.'),
            'user' => $schema->string()->enum(['forge', 'root'])->default('forge')->description('System user to run as.'),
            'processes' => $schema->integer()->min(1)->max(20)->default(1)->description('Number of processes (1-20).'),
            'directory' => $schema->string()->description('Working directory. Leave unset to let Forge decide.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate([
            'command' => ['required', 'string'],
            'user' => ['nullable', 'in:forge,root'],
            'processes' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteId = $request->get('site_id') !== null ? (int) $request->get('site_id') : null;
        $command = (string) $request->get('command');
        $user = (string) ($request->get('user') ?? 'forge');
        $processes = (int) ($request->get('processes') ?? 1);
        $directory = $request->get('directory') !== null ? (string) $request->get('directory') : null;
        $name = $request->get('name') !== null && $request->get('name') !== '' ? (string) $request->get('name') : $command;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $siteId !== null
                ? $this->siteTarget($ctx, $org, $serverId, $siteId)
                : $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $command, $user, $processes, $directory, $name) {
                $attrs = ['name' => $name, 'command' => $command, 'user' => $user, 'processes' => $processes]
                    + ($siteId !== null ? ['site_id' => $siteId] : [])
                    + ($directory !== null ? ['directory' => $directory] : []);

                $created = $ctx->forge->createBackgroundProcess($org, $serverId, $attrs);
                $id = $created['id'] ?? null;

                $suffix = ($siteId !== null ? " for site {$siteId}" : '').($user === 'root' ? ' as root' : '');
                $label = $id !== null ? "Worker {$id}" : 'Worker';
                $ctx->summary = 'Created worker '.($id !== null ? "{$id} " : '')."on server {$serverId}{$suffix}.";

                return "{$label} created on server {$this->serverName($ctx, $org, $serverId)}{$suffix}.";
            },
        );
    }
}
