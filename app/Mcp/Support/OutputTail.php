<?php

declare(strict_types=1);

namespace App\Mcp\Support;

/** Keeps the last N lines of long tool output (logs, command output). */
final class OutputTail
{
    /**
     * @param  int|null  $lines  null uses `forge-mcp.log_tail_default`; clamped to 1..`forge-mcp.log_tail_max`
     */
    public static function tail(string $text, ?int $lines): string
    {
        $max = max(1, (int) config('forge-mcp.log_tail_max', 1000));
        $limit = min($max, max(1, $lines ?? (int) config('forge-mcp.log_tail_default', 200)));

        $all = preg_split('/\r?\n/', rtrim($text, "\r\n")) ?: [];
        $total = count($all);

        if ($text === '' || $total <= $limit) {
            return $text;
        }

        return "[showing last {$limit} of {$total} lines]\n".implode("\n", array_slice($all, -$limit));
    }
}
