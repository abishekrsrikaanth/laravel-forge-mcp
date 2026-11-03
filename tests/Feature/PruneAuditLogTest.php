<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

function pruneEntry(User $user, string $summary, $at): void
{
    AuditEntry::create([
        'user_id' => $user->id, 'token_name' => 't', 'tool' => 'x', 'outcome' => 'success',
        'summary' => $summary, 'arguments' => [], 'duration_ms' => 1, 'created_at' => $at,
    ]);
}

it('prunes entries older than the retention window and keeps newer ones', function () {
    $user = User::factory()->create();
    pruneEntry($user, 'old', now()->subDays(91));
    pruneEntry($user, 'recent', now()->subDays(89));

    $this->artisan('model:prune', ['--model' => [AuditEntry::class]])->assertSuccessful();

    expect(AuditEntry::pluck('summary')->all())->toBe(['recent']);
});

it('honours the configured retention', function () {
    config(['forge-mcp.audit_retention_days' => 7]);
    $user = User::factory()->create();
    pruneEntry($user, 'old', now()->subDays(8));
    pruneEntry($user, 'recent', now()->subDays(6));

    $this->artisan('model:prune', ['--model' => [AuditEntry::class]])->assertSuccessful();

    expect(AuditEntry::pluck('summary')->all())->toBe(['recent']);
});

it('schedules pruning daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'model:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->command)->toContain('AuditEntry');
});
