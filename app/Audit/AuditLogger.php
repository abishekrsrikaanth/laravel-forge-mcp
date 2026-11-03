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
 * (the Forge API token, the target's known secrets when the caller supplies them, plus the scrubber's token/assignment patterns) before they are stored.
 * Callers pass arguments already reduced by the tool's redactArguments(). Arguments are capped:
 * each string at ARGUMENT_STRING_LIMIT characters, the whole set at ARGUMENTS_BYTE_LIMIT bytes of JSON.
 */
final readonly class AuditLogger
{
    private const int SUMMARY_LIMIT = 2000;

    /** Characters kept of any single string argument. */
    public const int ARGUMENT_STRING_LIMIT = 4000;

    /** Bytes the JSON-encoded arguments may take; larger sets are replaced by a marker plus their ids. */
    public const int ARGUMENTS_BYTE_LIMIT = 16384;

    public const string TRUNCATED_SUFFIX = '…[truncated]';

    public function __construct(private SecretScrubber $scrubber) {}

    /** @param  array<string, mixed>  $arguments */
    public function record(ToolContext $ctx, string $tool, ?Target $target, string $outcome, string $summary, array $arguments, int $durationMs, ?SecretSet $secrets = null): void
    {
        $secrets = self::forgeTokenSecret($ctx)->merge($secrets ?? SecretSet::empty());

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
            'arguments' => $this->limit($this->scrubArguments($arguments, $secrets)),
            'duration_ms' => max(0, $durationMs),
        ]);
    }

    public static function forgeTokenSecret(ToolContext $ctx): SecretSet
    {
        return SecretSet::of(['FORGE_API_TOKEN' => $ctx->connection->token]);
    }

    /**
     * Cap each string (after scrubbing, so a cut never exposes part of a secret), then the encoded size:
     * an oversized set becomes {"_truncated": true} plus its top-level organization and *_id values.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    private function limit(array $arguments): array
    {
        $arguments = $this->truncateStrings($arguments);

        $encoded = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded !== false && strlen($encoded) <= self::ARGUMENTS_BYTE_LIMIT) {
            return $arguments;
        }

        $kept = ['_truncated' => true];
        foreach ($arguments as $key => $value) {
            $isId = $key === 'organization' || $key === 'id' || str_ends_with((string) $key, '_id');
            if ($isId && (is_int($value) || (is_string($value) && strlen($value) <= 255))) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    private function truncateStrings(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (is_string($value) && mb_strlen($value) > self::ARGUMENT_STRING_LIMIT) {
                $arguments[$key] = mb_substr($value, 0, self::ARGUMENT_STRING_LIMIT).self::TRUNCATED_SUFFIX;
            } elseif (is_array($value)) {
                $arguments[$key] = $this->truncateStrings($value);
            }
        }

        return $arguments;
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
