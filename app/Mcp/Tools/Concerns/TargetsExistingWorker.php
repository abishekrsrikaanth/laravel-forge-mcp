<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\ToolContext;
use App\Permissions\Target;

/** Target closure for tools acting on an existing worker: validates org/server first, then authorises on every candidate site. */
trait TargetsExistingWorker
{
    /** @return \Closure(ToolContext): Target */
    protected function existingWorkerTarget(string $org, int $serverId, int $workerId): \Closure
    {
        return function (ToolContext $ctx) use ($org, $serverId, $workerId): Target {
            $this->serverTarget($ctx, $org, $serverId);

            $worker = $ctx->forge->backgroundProcess($org, $serverId, $workerId);

            return $this->workerTarget($ctx, $org, $serverId, $worker, $this->capability());
        };
    }
}
