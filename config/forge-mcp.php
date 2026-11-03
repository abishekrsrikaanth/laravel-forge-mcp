<?php

return [
    // Seconds to wait when polling Forge for a long-running operation.
    'poll_timeout' => (int) env('FORGE_MCP_POLL_TIMEOUT', 20),

    // Seconds to cache list responses (servers, sites, ...).
    'list_cache_ttl' => (int) env('FORGE_MCP_LIST_CACHE_TTL', 60),

    // Seconds to cache secrets (environment variables, etc.).
    'secrets_cache_ttl' => (int) env('FORGE_MCP_SECRETS_CACHE_TTL', 600),

    // Default and maximum number of log lines returned by log tools.
    'log_tail_default' => (int) env('FORGE_MCP_LOG_TAIL_DEFAULT', 200),
    'log_tail_max' => (int) env('FORGE_MCP_LOG_TAIL_MAX', 1000),

    // Days to keep audit log entries.
    'audit_retention_days' => (int) env('FORGE_MCP_AUDIT_RETENTION_DAYS', 90),

    // Minimum secret length that is scrubbed from tool output.
    'scrub_min_length' => (int) env('FORGE_MCP_SCRUB_MIN_LENGTH', 8),
];
