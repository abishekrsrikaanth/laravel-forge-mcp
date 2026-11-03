<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolDenied;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\RejectsControlCharacters;
use App\Permissions\Capability;
use App\Permissions\Target;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create a background process (queue worker or daemon) on a Forge server, optionally for a site. Runs as the forge user unless user=root is given (root needs the permission on the whole server). With site_id, a directory must be inside the site root. This executes an arbitrary command on the server.')]
#[IsDestructive]
class CreateWorker extends ForgeTool
{
    use RejectsControlCharacters;

    protected string $name = 'create_worker';

    protected function capability(): Capability
    {
        return Capability::ConfigureWorkers;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id. Defaults to the configured server if omitted.'),
            'command' => $schema->string()->required()->description('Command to run, e.g. "php artisan queue:work --tries=3".'),
            'site_id' => $schema->integer()->description('Site the process belongs to. Permission is then checked on that site.'),
            'name' => $schema->string()->description('Process name. Defaults to the command.'),
            'user' => $schema->string()->enum(['forge', 'root'])->default('forge')->description('System user to run as. root needs the permission on the whole server.'),
            'processes' => $schema->integer()->min(1)->max(20)->default(1)->description('Number of processes (1-20).'),
            'directory' => $schema->string()->description('Working directory (absolute). With site_id it must be inside the site root. Leave unset to let Forge decide.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate([
            'command' => ['required', 'string'],
            'user' => ['nullable', 'in:forge,root'],
            'processes' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        foreach (['command', 'directory', 'name', 'user'] as $field) {
            if (is_string($request->get($field))) {
                $this->assertSingleLine($field, $request->get($field));
            }
        }

        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $siteId = $request->get('site_id') !== null ? (int) $request->get('site_id') : null;
        $command = (string) $request->get('command');
        $user = (string) ($request->get('user') ?? 'forge');
        $processes = (int) ($request->get('processes') ?? 1);
        $directory = $request->get('directory') !== null ? (string) $request->get('directory') : null;
        $name = $request->get('name') !== null && $request->get('name') !== '' ? (string) $request->get('name') : $command;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->target($ctx, $org, $serverId, $siteId, $user, $directory),
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

    /**
     * The site (or server) target. A root worker needs the capability on the whole server as well,
     * and a site worker's directory must lie inside the site's root directory: the `forge` user and
     * supervisor are shared by every site, so a site grant must not reach beyond the site.
     *
     * @throws ToolError|ToolDenied
     */
    private function target(ToolContext $ctx, string $org, int $serverId, ?int $siteId, string $user, ?string $directory): Target
    {
        if ($siteId === null) {
            return $this->serverTarget($ctx, $org, $serverId);
        }

        $target = $this->siteTarget($ctx, $org, $serverId, $siteId);

        if (! $this->permits($ctx, $target)) {
            return $target; // guarded() denies on the site before anything about it is revealed
        }

        if ($directory !== null) {
            $this->assertInsideSiteRoot($ctx, $org, $serverId, $siteId, $directory);
        }

        return $user === 'root'
            ? $this->requireServerPermission($ctx, $target, 'Running a worker as root')
            : $target;
    }

    /** @throws ToolError */
    private function assertInsideSiteRoot(ToolContext $ctx, string $org, int $serverId, int $siteId, string $directory): void
    {
        $root = null;
        foreach ($ctx->forge->sites($org, $serverId) as $site) {
            if ((int) ($site['id'] ?? 0) === $siteId) {
                $root = self::normalisePath((string) ($site['root_directory'] ?? ''));

                break;
            }
        }

        $dir = self::normalisePath($directory);

        if ($root === null || $root === '/' || $dir === null || ($dir !== $root && ! str_starts_with($dir, $root.'/'))) {
            throw new ToolError("The directory must be inside the root directory of site {$siteId}".($root !== null && $root !== '/' ? " ({$root})" : '').'. Omit `directory` to let Forge use the site directory, or omit `site_id` (needs the permission on the server).');
        }
    }

    /** An absolute path without `.`, empty segments or trailing slashes; null when it is relative or contains `..`. */
    private static function normalisePath(string $path): ?string
    {
        $path = trim($path);
        if (! str_starts_with($path, '/')) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // Not resolved lexically: through a symlink (e.g. `current`) `..` lands elsewhere.
                return null;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
