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

#[Description('List recent deployments of a Forge site: id, status, start time, commit hash and commit message.')]
#[IsReadOnly]
class ListDeployments extends ForgeTool
{
    protected string $name = 'list_deployments';

    protected function capability(): Capability
    {
        return Capability::View;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id the site lives on.'),
            'site_id' => $schema->integer()->required()->description('Forge site id (from list_sites).'),
            'limit' => $schema->integer()->description('Number of deployments to return (default 10, max 50).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteId = (int) $request->get('site_id');
        $limit = min(50, max(1, (int) ($request->get('limit') ?? 10)));

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $limit) {
                $deployments = array_slice($ctx->forge->deployments($org, $serverId, $siteId, $limit), 0, $limit);
                $ctx->summary = "Listed deployments of site {$siteId}.";

                if ($deployments === []) {
                    return 'No deployments found.';
                }

                return implode("\n", array_map(static function (array $d): string {
                    $hash = is_string($d['commit_hash'] ?? null) && $d['commit_hash'] !== '' ? substr($d['commit_hash'], 0, 7) : '-';
                    $message = is_string($d['commit_message'] ?? null) ? trim(strtok($d['commit_message'], "\r\n") ?: '') : '';

                    return implode(' ', [
                        $d['id'] ?? '-',
                        $d['status'] ?? '-',
                        $d['started_at'] ?? '-',
                        $hash,
                        $message !== '' ? $message : '-',
                    ]);
                }, $deployments));
            },
        );
    }
}
