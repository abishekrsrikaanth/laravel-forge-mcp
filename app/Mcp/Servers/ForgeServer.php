<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\DeploySite;
use App\Mcp\Tools\GetDeploymentLog;
use App\Mcp\Tools\GetDeploymentStatus;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetSite;
use App\Mcp\Tools\GetSiteLog;
use App\Mcp\Tools\GetWorkerLog;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListOrganizations;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListSites;
use App\Mcp\Tools\ListWorkers;
use Laravel\Mcp\Server;

class ForgeServer extends Server
{
    protected string $name = 'Forge MCP';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Operate Laravel Forge servers and sites on behalf of the user.

        - Discover ids first: call `list_organizations`, then `list_servers` and `list_sites`. Never guess organization slugs or server/site ids.
        - When only one organization is available, the `organization` argument may be omitted.
        - Permissions are set by the user per access token in the Forge MCP dashboard. Tools you cannot use are not listed.
        - Do not retry a denied action, and do not try to reach the same result through another tool: tell the user which permission is missing so they can enable it in the Forge MCP dashboard.
        - Secrets in output are replaced with [REDACTED] markers; do not try to recover them.
    MARKDOWN;

    protected array $tools = [
        ListOrganizations::class,
        ListServers::class,
        GetServer::class,
        ListSites::class,
        GetSite::class,
        GetDeploymentStatus::class,
        ListDeployments::class,
        GetDeploymentLog::class,
        DeploySite::class,
        GetSiteLog::class,
        ListWorkers::class,
        GetWorkerLog::class,
    ];
}
