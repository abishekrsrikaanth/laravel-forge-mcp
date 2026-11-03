<?php

return [
    // Seconds to wait when polling Forge for a long-running operation.
    'poll_timeout' => 20,

    // Seconds to cache list responses (servers, sites, ...).
    'list_cache_ttl' => 60,

    // Seconds to cache secrets (environment variables, etc.).
    'secrets_cache_ttl' => 600,

    // Default and maximum number of log lines returned by log tools.
    'log_tail_default' => 200,
    'log_tail_max' => 1000,

    // Days to keep audit log entries.
    'audit_retention_days' => 90,

    // Minimum secret length that is scrubbed from tool output.
    'scrub_min_length' => 8,
];
