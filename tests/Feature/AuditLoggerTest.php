<?php

use App\Audit\AuditLogger;
use App\Forge\ForgeClient;
use App\Mcp\Support\ToolContext;
use App\Models\AuditEntry;
use App\Permissions\Target;

function auditLogContext(): ToolContext
{
    $user = mcpOwner(['acme']);

    return new ToolContext($user, mcpToken($user), new ForgeClient(MCP_FORGE_TOKEN), $user->forgeConnection);
}

/** @param  array<string, mixed>  $arguments */
function auditLogRecord(array $arguments): AuditEntry
{
    app(AuditLogger::class)->record(auditLogContext(), 'update_worker', new Target('acme', 1), AuditEntry::SUCCESS, 'Updated.', $arguments, 5);

    return AuditEntry::query()->latest('id')->firstOrFail();
}

it('truncates a long string argument to 4,000 characters', function () {
    $entry = auditLogRecord(['server_id' => 1, 'config' => str_repeat('é', 5000), 'nested' => ['x' => str_repeat('b', 4500)]]);

    expect($entry->arguments['config'])->toBe(str_repeat('é', 4000).'…[truncated]')
        ->and($entry->arguments['nested']['x'])->toBe(str_repeat('b', 4000).'…[truncated]')
        ->and($entry->arguments['server_id'])->toBe(1);
});

it('leaves arguments within the limits untouched', function () {
    $entry = auditLogRecord(['server_id' => 1, 'command' => str_repeat('c', 4000)]);

    expect($entry->arguments)->toBe(['server_id' => 1, 'command' => str_repeat('c', 4000)]);
});

it('replaces arguments over 16 KB with a marker that keeps the ids', function () {
    $entry = auditLogRecord([
        'organization' => 'acme',
        'server_id' => 1,
        'site_id' => 12,
        'worker_id' => 5,
        'remove' => array_map(fn (int $i) => "KEY_NUMBER_{$i}", range(1, 2000)),
    ]);

    expect($entry->arguments)->toBe(['_truncated' => true, 'organization' => 'acme', 'server_id' => 1, 'site_id' => 12, 'worker_id' => 5]);
});
