<?php

/**
 * An integer env value: empty or non-numeric falls back to the default, then it is clamped to the minimum.
 */
$int = static function (string $key, int $default, int $min): int {
    $raw = env($key);
    $value = is_numeric($raw) ? (int) $raw : $default;

    return max($min, $value);
};

$logTailDefault = $int('FORGE_MCP_LOG_TAIL_DEFAULT', 200, 1);

return [
    // Seconds to wait when polling Forge for a long-running operation.
    'poll_timeout' => $int('FORGE_MCP_POLL_TIMEOUT', 20, 1),

    // Seconds to cache list responses (servers, sites, ...).
    'list_cache_ttl' => $int('FORGE_MCP_LIST_CACHE_TTL', 60, 0),

    // Seconds to cache secrets (environment variables, etc.).
    'secrets_cache_ttl' => $int('FORGE_MCP_SECRETS_CACHE_TTL', 600, 0),

    // Default and maximum number of log lines returned by log tools.
    'log_tail_default' => $logTailDefault,
    'log_tail_max' => max($logTailDefault, $int('FORGE_MCP_LOG_TAIL_MAX', 1000, 1)),

    // Days to keep audit log entries.
    'audit_retention_days' => $int('FORGE_MCP_AUDIT_RETENTION_DAYS', 90, 1),

    // Minimum secret length that is scrubbed from tool output.
    'scrub_min_length' => $int('FORGE_MCP_SCRUB_MIN_LENGTH', 8, 4),
];
