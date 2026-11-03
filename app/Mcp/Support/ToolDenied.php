<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Permissions\Target;

/**
 * A refusal on permission/exposure grounds. Audited as `denied` (with the target when known);
 * the message is returned to the agent.
 */
final class ToolDenied extends ToolError
{
    public function __construct(string $message, public readonly ?Target $target = null)
    {
        parent::__construct($message);
    }
}
