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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Show whether Horizon, Octane or Reverb is enabled and installed on a Forge site, with its backing processes.')]
#[IsReadOnly]
class GetIntegrationStatus extends ForgeTool
{
    private const array INTEGRATIONS = ['horizon', 'octane', 'reverb'];

    protected string $name = 'get_integration_status';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id the site lives on. Defaults to the configured server if omitted.'),
            'site_id' => $schema->integer()->description('Forge site id (from list_sites). Defaults to the configured site if omitted.'),
            'integration' => $schema->string()->enum(self::INTEGRATIONS)->required()->description('Which integration to inspect.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $siteId = $this->resolveSiteId($request, $org, $serverId);
        $integration = (string) $request->get('integration');

        if (! in_array($integration, self::INTEGRATIONS, true)) {
            throw new ToolError('Invalid integration. Use one of: '.implode(', ', self::INTEGRATIONS).'.');
        }

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $integration) {
                $status = $ctx->forge->integration($org, $serverId, $siteId, $integration);
                $ctx->summary = "Read {$integration} status of site {$siteId}.";

                $lines = ["{$integration}: enabled=".self::show($status['enabled'] ?? null).' installed='.self::show($status["{$integration}_installed"] ?? null)];

                $processes = is_array($status['processes'] ?? null) ? $status['processes'] : [];
                if ($processes === []) {
                    $lines[] = 'No backing processes reported.';
                }
                foreach ($processes as $p) {
                    $lines[] = implode(' ', [self::show($p['id'] ?? null), self::show($p['status'] ?? null), self::show($p['command'] ?? null)]);
                }

                return implode("\n", $lines);
            },
        );
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) && $value !== '' => (string) $value,
            default => '-',
        };
    }
}
