<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\ToolContext;
use App\Permissions\PermissionEngine;
use App\Permissions\Target;

/**
 * For list tools that check the capability per item: the up-front check only needs the capability
 * to be granted somewhere. The tool itself must then filter every item with PermissionEngine::allows().
 */
trait FiltersItemsByPermission
{
    protected function permits(ToolContext $ctx, Target $target): bool
    {
        return app(PermissionEngine::class)->allowsAnywhere($ctx->token, $this->capability());
    }
}
