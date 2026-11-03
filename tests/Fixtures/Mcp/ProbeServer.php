<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Tools\ListOrganizations;
use Laravel\Mcp\Server;

/** Test-only server: the real read tool plus probes that exercise ForgeTool's guards. */
class ProbeServer extends Server
{
    protected string $name = 'Probe';

    protected array $tools = [
        ListOrganizations::class,
        DeployProbe::class,
        SiteProbe::class,
        ServerProbe::class,
        LeakProbe::class,
    ];
}
