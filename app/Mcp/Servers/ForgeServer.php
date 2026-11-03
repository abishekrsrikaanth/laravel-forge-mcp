<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DiagnoseFailedDeploy;
use App\Mcp\Tools\CreateWorker;
use App\Mcp\Tools\DeleteWorker;
use App\Mcp\Tools\DeploySite;
use App\Mcp\Tools\DisableMaintenanceMode;
use App\Mcp\Tools\EnableMaintenanceMode;
use App\Mcp\Tools\GetCommandResult;
use App\Mcp\Tools\GetDeploymentLog;
use App\Mcp\Tools\GetDeploymentScript;
use App\Mcp\Tools\GetDeploymentStatus;
use App\Mcp\Tools\GetIntegrationStatus;
use App\Mcp\Tools\GetMaintenanceMode;
use App\Mcp\Tools\GetScheduledJobOutput;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetServerEventOutput;
use App\Mcp\Tools\GetSite;
use App\Mcp\Tools\GetSiteEnv;
use App\Mcp\Tools\GetSiteLog;
use App\Mcp\Tools\GetWorkerLog;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListOrganizations;
use App\Mcp\Tools\ListScheduledJobs;
use App\Mcp\Tools\ListServerEvents;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListSites;
use App\Mcp\Tools\ListWorkers;
use App\Mcp\Tools\RebootServer;
use App\Mcp\Tools\ResetDeploymentState;
use App\Mcp\Tools\RestartNginx;
use App\Mcp\Tools\RestartPhpFpm;
use App\Mcp\Tools\RestartService;
use App\Mcp\Tools\RestartWorker;
use App\Mcp\Tools\RunSiteCommand;
use App\Mcp\Tools\SetQuickDeploy;
use App\Mcp\Tools\SetSiteEnvValues;
use App\Mcp\Tools\StartWorker;
use App\Mcp\Tools\StopWorker;
use App\Mcp\Tools\UpdateDeploymentScript;
use App\Mcp\Tools\UpdateWorker;
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

    /** The package defaults (15/50) would page tools out of view for clients that ignore nextCursor. */
    public int $defaultPaginationLength = 200;

    public int $maxPaginationLength = 200;

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
        RestartNginx::class,
        RestartPhpFpm::class,
        RestartWorker::class,
        StopWorker::class,
        StartWorker::class,
        CreateWorker::class,
        UpdateWorker::class,
        DeleteWorker::class,
        GetSiteEnv::class,
        SetSiteEnvValues::class,
        RunSiteCommand::class,
        GetCommandResult::class,
        GetDeploymentScript::class,
        UpdateDeploymentScript::class,
        SetQuickDeploy::class,
        ResetDeploymentState::class,
        RebootServer::class,
        GetIntegrationStatus::class,
        ListScheduledJobs::class,
        GetScheduledJobOutput::class,
        ListServerEvents::class,
        GetServerEventOutput::class,
        RestartService::class,
        GetMaintenanceMode::class,
        EnableMaintenanceMode::class,
        DisableMaintenanceMode::class,
    ];

    protected array $prompts = [
        DiagnoseFailedDeploy::class,
    ];
}
