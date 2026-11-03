<?php

const FORGE_MCP_CONFIG_ENV = [
    'FORGE_MCP_POLL_TIMEOUT' => ['poll_timeout', 20, '45', 45],
    'FORGE_MCP_LIST_CACHE_TTL' => ['list_cache_ttl', 60, '30', 30],
    'FORGE_MCP_SECRETS_CACHE_TTL' => ['secrets_cache_ttl', 600, '120', 120],
    'FORGE_MCP_LOG_TAIL_DEFAULT' => ['log_tail_default', 200, '50', 50],
    'FORGE_MCP_LOG_TAIL_MAX' => ['log_tail_max', 1000, '5000', 5000],
    'FORGE_MCP_AUDIT_RETENTION_DAYS' => ['audit_retention_days', 90, '365', 365],
    'FORGE_MCP_SCRUB_MIN_LENGTH' => ['scrub_min_length', 8, '12', 12],
];

afterEach(function () {
    foreach (['FORGE_MCP_LOG_TAIL_DEFAULT', 'FORGE_MCP_LOG_TAIL_MAX'] as $n) {
        unset($_ENV[$n], $_SERVER[$n]);
    }
    foreach (array_keys(FORGE_MCP_CONFIG_ENV) as $name) {
        unset($_ENV[$name], $_SERVER[$name]);
    }
});

it('uses the documented defaults when no env variable is set', function () {
    $config = require config_path('forge-mcp.php');

    foreach (FORGE_MCP_CONFIG_ENV as [$key, $default]) {
        expect($config[$key])->toBe($default);
    }
});

it('reads every tunable from its env variable as an integer', function () {
    foreach (FORGE_MCP_CONFIG_ENV as $name => [, , $value]) {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    $config = require config_path('forge-mcp.php');

    foreach (FORGE_MCP_CONFIG_ENV as [$key, , , $expected]) {
        expect($config[$key])->toBe($expected);
    }
});

it('lists every tunable in .env.example', function () {
    $example = file_get_contents(base_path('.env.example'));

    foreach (array_keys(FORGE_MCP_CONFIG_ENV) as $name) {
        expect($example)->toContain("# {$name}=");
    }
});

it('falls back to defaults for empty or non-numeric env values', function (string $value) {
    foreach (array_keys(FORGE_MCP_CONFIG_ENV) as $name) {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    $config = require config_path('forge-mcp.php');

    foreach (FORGE_MCP_CONFIG_ENV as [$key, $default]) {
        expect($config[$key])->toBe($default);
    }
})->with(['empty' => '', 'text' => 'abc']);

it('clamps zero and negative env values to their minimums', function (string $value) {
    foreach (array_keys(FORGE_MCP_CONFIG_ENV) as $name) {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    $config = require config_path('forge-mcp.php');

    expect($config['poll_timeout'])->toBe(1)
        ->and($config['list_cache_ttl'])->toBe(0)
        ->and($config['secrets_cache_ttl'])->toBe(0)
        ->and($config['log_tail_default'])->toBe(1)
        ->and($config['log_tail_max'])->toBe(1)
        ->and($config['audit_retention_days'])->toBe(1)
        ->and($config['scrub_min_length'])->toBe(4);
})->with(['zero' => '0', 'negative' => '-5']);

it('never lets log_tail_max drop below log_tail_default', function () {
    $_ENV['FORGE_MCP_LOG_TAIL_DEFAULT'] = $_SERVER['FORGE_MCP_LOG_TAIL_DEFAULT'] = '500';
    $_ENV['FORGE_MCP_LOG_TAIL_MAX'] = $_SERVER['FORGE_MCP_LOG_TAIL_MAX'] = '100';

    $config = require config_path('forge-mcp.php');

    expect($config['log_tail_default'])->toBe(500)->and($config['log_tail_max'])->toBe(500);
});
