<?php

declare(strict_types=1);

namespace App\Audit;

use App\Mcp\Support\ToolContext;
use App\Models\AuditEntry;
use App\Permissions\Target;
use App\Security\SecretScrubber;
use App\Security\SecretSet;

/**
 * Writes one audit row per MCP tool call. The summary and every string argument are scrubbed
 * (the Forge API token plus the scrubber's token/assignment patterns) before they are stored.
 * Callers pass arguments already reduced by the tool's redactArguments().
 */
final readonly class AuditLogger
{
    private const int SUMMARY_LIMIT = 2000;

    public function __construct(private SecretScrubber $scrubber) {}

    /** @param  array<string, mixed>  $arguments */
    public function record(ToolContext $ctx, string $tool, ?Target $target, string $outcome, string $summary, array $arguments, int $durationMs): void
    {
        $secrets = self::forgeTokenSecret($ctx);

        AuditEntry::query()->create([
            'user_id' => $ctx->user->getKey(),
            'personal_access_token_id' => $ctx->token->getKey(),
            'token_name' => $ctx->token->name,
            'tool' => $tool,
            'organization' => $target?->organization,
            'server_id' => $target?->serverId,
            'site_id' => $target?->siteId,
            'outcome' => $outcome,
            'summary' => mb_strimwidth($this->scrubber->scrub($summary, $secrets), 0, self::SUMMARY_LIMIT, '…'),
            'arguments' => $this->scrubArguments($arguments, $secrets),
            'duration_ms' => max(0, $durationMs),
        ]);
    }

    public static function forgeTokenSecret(ToolContext $ctx): SecretSet
    {
        return SecretSet::of(['FORGE_API_TOKEN' => $ctx->connection->token]);
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    private function scrubArguments(array $arguments, SecretSet $secrets): array
    {
        foreach ($arguments as $key => $value) {
            $arguments[$key] = match (true) {
                is_string($value) => $this->scrubber->scrub($value, $secrets),
                is_array($value) => $this->scrubArguments($value, $secrets),
                default => $value,
            };
        }

        return $arguments;
    }
}
