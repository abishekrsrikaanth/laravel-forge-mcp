<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use RuntimeException;

/**
 * An agent-facing error: its message is returned to the agent as a tool error. Never put secrets in it.
 */
class ToolError extends RuntimeException {}
