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

#[Description('List scheduled jobs (cron) of a Forge server, or of one site when site_id is given: id, frequency, user and command.')]
#[IsReadOnly]
class ListScheduledJobs extends ForgeTool
{
    protected string $name = 'list_scheduled_jobs';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id. Defaults to the configured server if omitted.'),
            'site_id' => $schema->integer()->description('Forge site id; omit to list the server-level jobs.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $siteId = $request->get('site_id') !== null ? (int) $request->get('site_id') : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $siteId !== null ? $this->siteTarget($ctx, $org, $serverId, $siteId) : $this->serverWideTarget($ctx, $org, $serverId, Capability::View),
            function (ToolContext $ctx) use ($org, $serverId, $siteId) {
                $jobs = $ctx->forge->scheduledJobs($org, $serverId, $siteId);
                $ctx->summary = $siteId !== null ? "Listed scheduled jobs of site {$siteId}." : "Listed scheduled jobs of server {$serverId}.";

                if ($jobs === []) {
                    return 'No scheduled jobs found.';
                }

                $total = count($jobs);
                $lines = array_map(static function (array $j): string {
                    $frequency = is_string($j['frequency'] ?? null) && $j['frequency'] !== '' && $j['frequency'] !== 'custom' ? $j['frequency'] : ($j['cron'] ?? null);
                    $show = static fn (mixed $v): string => is_scalar($v) && $v !== '' ? (string) $v : '-';

                    return implode(' ', [$show($j['id'] ?? null), $show($frequency), $show($j['user'] ?? null), $show($j['command'] ?? null)]);
                }, array_slice($jobs, 0, 100));

                if ($total > 100) {
                    $lines[] = '… '.($total - 100).' more';
                }

                return implode("\n", $lines);
            },
        );
    }
}
