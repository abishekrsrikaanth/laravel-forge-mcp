<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\OutputTail;
use App\Mcp\Support\ToolContext;

trait FormatsCommandResult
{
    /** @var list<string> */
    private const array COMMAND_DONE = ['finished', 'failed', 'timeout'];

    /** @param  array<string, mixed>  $command */
    private function commandIsDone(array $command): bool
    {
        return in_array($command['status'] ?? null, self::COMMAND_DONE, true);
    }

    /**
     * Render a command record: a follow-up hint while it runs, else exit code, status and the output tail.
     *
     * @param  array<string, mixed>  $command
     */
    private function commandReport(ToolContext $ctx, string $org, int $serverId, int $siteId, int $commandId, array $command, ?int $lines): string
    {
        $status = (string) ($command['status'] ?? 'pending');

        if (! $this->commandIsDone($command)) {
            return "Command {$commandId} is still {$status}. Call get_command_result with command_id {$commandId}.";
        }

        $exit = isset($command['exit_code']) && is_scalar($command['exit_code']) ? (string) $command['exit_code'] : 'unknown';
        $output = $ctx->forge->commandOutput($org, $serverId, $siteId, $commandId);
        $report = "exit {$exit}, status {$status}\n".($output === '' ? 'The command produced no output.' : OutputTail::tail($output, $lines));

        $errorOutput = $command['error_output'] ?? null;
        if (is_string($errorOutput) && trim($errorOutput) !== '') {
            $report .= "\nerror output:\n".OutputTail::tail($errorOutput, $lines);
        }

        return $report;
    }
}
