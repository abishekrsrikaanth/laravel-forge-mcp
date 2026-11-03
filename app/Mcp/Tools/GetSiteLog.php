<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\OutputTail;
use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolError;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read the application, nginx access or nginx error log of a Forge site (last lines only). Secrets are redacted.')]
#[IsReadOnly]
class GetSiteLog extends ForgeTool
{
    private const array TYPES = ['application', 'nginx-access', 'nginx-error'];

    protected string $name = 'get_site_log';

    protected function capability(): Capability
    {
        return Capability::ViewLogs;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id the site lives on. Defaults to the configured server if omitted.'),
            'site_id' => $schema->integer()->description('Forge site id (from list_sites). Defaults to the configured site if omitted.'),
            'type' => $schema->string()->enum(self::TYPES)->required()->description('Which log to read.'),
            'lines' => $schema->integer()->description('Number of trailing lines to return.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $siteId = $this->resolveSiteId($request, $org, $serverId);
        $type = (string) $request->get('type');
        $lines = $request->get('lines') !== null ? (int) $request->get('lines') : null;

        if (! in_array($type, self::TYPES, true)) {
            throw new ToolError('Invalid log type. Use one of: '.implode(', ', self::TYPES).'.');
        }

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $type, $lines) {
                $log = $ctx->forge->siteLog($org, $serverId, $siteId, $type);
                $ctx->summary = "Read {$type} log of site {$siteId}.";

                return $log === '' ? "The {$type} log is empty." : OutputTail::tail($log, $lines);
            },
        );
    }
}
