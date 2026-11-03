<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\ToolError;

trait RejectsControlCharacters
{
    /**
     * Refuse CR, LF, NUL and every other ASCII control character (0x00-0x1F, 0x7F). Forge renders
     * these values into a supervisor program config, where a newline would inject extra lines.
     *
     * @throws ToolError
     */
    protected function assertSingleLine(string $field, string $value): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ToolError("{$field} must be a single line without control characters.");
        }
    }

    /** @throws ToolError */
    protected function assertNoNul(string $field, string $value): void
    {
        if (str_contains($value, "\0")) {
            throw new ToolError("{$field} must not contain NUL characters.");
        }
    }
}
