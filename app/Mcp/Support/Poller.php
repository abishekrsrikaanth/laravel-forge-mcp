<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Bounded polling: waits 1, 2, 3, 5, 5... seconds between fetches and gives up at `forge-mcp.poll_timeout`.
 * Elapsed time is the larger of the wall clock (Carbon, so slow fetches count) and the sum of the sleeps
 * (so a faked Sleep that does not move the clock still terminates).
 */
final class Poller
{
    /** @var list<int> */
    private const DELAYS = [1, 2, 3, 5];

    /**
     * @param  Closure(): mixed  $fetch
     * @param  Closure(mixed): bool  $isDone
     * @return array{result: mixed, done: bool}
     */
    public static function until(Closure $fetch, Closure $isDone, ?int $timeoutSeconds = null): array
    {
        $timeout = max(0, $timeoutSeconds ?? (int) config('forge-mcp.poll_timeout', 20));
        $started = Carbon::now();
        $slept = 0;
        $attempt = 0;

        while (true) {
            $result = $fetch();

            if ($isDone($result)) {
                return ['result' => $result, 'done' => true];
            }

            $elapsed = max($slept, $started->diffInMilliseconds(Carbon::now(), true) / 1000);

            if ($elapsed >= $timeout) {
                return ['result' => $result, 'done' => false];
            }

            $delay = (int) max(1, min(self::DELAYS[min($attempt, count(self::DELAYS) - 1)], (int) floor($timeout - $elapsed)));
            Sleep::for($delay)->seconds();
            $slept += $delay;
            $attempt++;
        }
    }
}
