<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolError;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Reload or restart PHP-FPM on a Forge server. The PHP version defaults to the site version (when site_id is given) or the server version; pass version (e.g. php84) to override. Restart briefly interrupts PHP requests; reload is gentler.')]
#[IsDestructive]
class RestartPhpFpm extends ForgeTool
{
    /** Forge's PhpVersion enum, exactly. */
    private const VERSIONS = ['php5', 'php56-old', 'php56', 'php70', 'php71', 'php72', 'php73', 'php74', 'php80', 'php81', 'php82', 'php83', 'php84', 'php85'];

    protected string $name = 'restart_php_fpm';

    protected function capability(): Capability
    {
        return Capability::RestartServices;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id (from list_servers). Defaults to the configured server if omitted.'),
            'action' => $schema->string()->enum(['reload', 'restart'])->default('reload')->description('reload (default) or restart.'),
            'site_id' => $schema->integer()->description('Optional site id, used only to detect the PHP version.'),
            'version' => $schema->string()->description('PHP version such as php84. Overrides detection.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $action = (string) ($request->get('action') ?? 'reload');
        $siteId = $request->get('site_id') !== null ? (int) $request->get('site_id') : null;
        $explicit = $request->get('version') !== null ? (string) $request->get('version') : null;

        return $this->guarded(
            $request,
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                // Authorisation is always server-level; site_id only has to belong to the server.
                $target = $this->serverTarget($ctx, $org, $serverId);
                if ($siteId !== null) {
                    $this->siteTarget($ctx, $org, $serverId, $siteId);
                }

                return $target;
            },
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $action, $explicit) {
                if (! in_array($action, ['reload', 'restart'], true)) {
                    throw new ToolError("Invalid action '{$action}'. Use reload or restart.");
                }

                if ($explicit !== null && ! in_array($explicit, self::VERSIONS, true)) {
                    throw new ToolError('Invalid PHP version. Use one of: '.implode(', ', self::VERSIONS).'.');
                }

                $version = $explicit
                    ?? ($siteId !== null ? $this->stringOrNull($ctx->forge->site($org, $siteId)['php_version'] ?? null) : null)
                    ?? $this->serverVersion($ctx, $org, $serverId);

                if ($version === null) {
                    throw new ToolError('Could not determine the PHP version; pass version explicitly (e.g. php84).');
                }

                if (! in_array($version, self::VERSIONS, true)) {
                    throw new ToolError("PHP version '{$version}' reported by Forge is not supported; pass version explicitly.");
                }

                $ctx->forge->phpAction($org, $serverId, $action === 'restart' ? 'reboot' : 'reload', $version);
                $ctx->summary = "PHP-FPM {$version} {$action} on server {$serverId}";

                return "PHP-FPM {$version} {$action} queued on server {$this->serverName($ctx, $org, $serverId)}.";
            },
        );
    }

    private function serverVersion(ToolContext $ctx, string $org, int $serverId): ?string
    {
        foreach ($ctx->forge->servers($org) as $server) {
            if ((int) ($server['id'] ?? 0) === $serverId) {
                return $this->stringOrNull($server['php_version'] ?? null);
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
