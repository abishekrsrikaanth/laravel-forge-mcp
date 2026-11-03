<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Forge\ForgeException;
use App\Mcp\Support\ToolContext;
use App\Mcp\Support\ToolError;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

/**
 * Replaces the deployment script. The script is kept in audit arguments on purpose (auditing it is
 * the point; the audit logger masks the site's secrets in it) but is never echoed back.
 */
#[Description('Replace the deployment script of a Forge site with the given content. The next deployment runs the new script. Returns only a line count, never the script.')]
#[IsDestructive]
class UpdateDeploymentScript extends ForgeTool
{
    private const MAX_LENGTH = 50000;

    protected string $name = 'update_deployment_script';

    protected function capability(): Capability
    {
        return Capability::ConfigureDeployments;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->required()->description('Forge server id the site lives on.'),
            'site_id' => $schema->integer()->required()->description('Forge site id (from list_sites).'),
            'content' => $schema->string()->required()->description('The full new deployment script (bash), up to 50,000 characters.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $content = $request->get('content');

        if (! is_string($content) || trim($content) === '') {
            throw new ToolError('content must be a non-empty string.');
        }

        if (strlen($content) > self::MAX_LENGTH || mb_strlen($content) > self::MAX_LENGTH) {
            throw new ToolError('content must be at most '.self::MAX_LENGTH.' characters.');
        }

        if (str_contains($content, "\0")) {
            throw new ToolError('content must not contain NUL bytes.');
        }

        $org = $this->resolveOrganization($request);
        $serverId = (int) $request->get('server_id');
        $siteId = (int) $request->get('site_id');

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $content) {
                try {
                    $ctx->forge->updateDeploymentScript($org, $serverId, $siteId, $content);
                } catch (Throwable $e) {
                    throw $this->withoutDetail($e);
                }

                $lines = substr_count(rtrim($content, "\r\n"), "\n") + 1;
                $ctx->summary = "Updated deployment script of site {$siteId} ({$lines} lines)";

                return "Deployment script updated for site {$siteId} ({$lines} lines).";
            },
        );
    }

    /** Forge's 422 detail may echo the submitted script; keep only the status. */
    private function withoutDetail(Throwable $e): Throwable
    {
        return $e instanceof ForgeException && $e->status === 422
            ? new ForgeException('Forge rejected the deployment script (422).', 422)
            : $e;
    }
}
