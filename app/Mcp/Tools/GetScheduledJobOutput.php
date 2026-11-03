<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\OutputTail;
use App\Mcp\Support\ToolContext;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read the last output of a scheduled job on a Forge server, or on one site when site_id is given (last lines only). Secrets are redacted.')]
#[IsReadOnly]
class GetScheduledJobOutput extends ForgeTool
{
    protected string $name = 'get_scheduled_job_output';

    protected function capability(): Capability
    {
        return Capability::ViewLogs;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id.'),
            'job_id' => $schema->integer()->required()->description('Scheduled job id (from list_scheduled_jobs).'),
            'site_id' => $schema->integer()->description('Forge site id when the job belongs to a site.'),
            'lines' => $schema->integer()->description('Number of trailing lines to return.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $jobId = (int) $request->get('job_id');
        $siteId = $request->get('site_id') !== null ? (int) $request->get('site_id') : null;
        $lines = $request->get('lines') !== null ? (int) $request->get('lines') : null;

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $siteId !== null ? $this->siteTarget($ctx, $org, $serverId, $siteId) : $this->serverTarget($ctx, $org, $serverId),
            function (ToolContext $ctx) use ($org, $serverId, $jobId, $siteId, $lines) {
                $output = $ctx->forge->scheduledJobOutput($org, $serverId, $jobId, $siteId);
                $ctx->summary = "Read output of scheduled job {$jobId}.";

                return $output === '' ? 'The job has no output.' : OutputTail::tail($output, $lines);
            },
        );
    }
}
