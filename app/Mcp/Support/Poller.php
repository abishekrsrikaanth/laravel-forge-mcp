<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Closure;
use Illuminate\Support\Sleep;

/** Bounded polling: waits 1, 2, 3, 5, 5... seconds between fetches and gives up at `forge-mcp.poll_timeout`. */
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
        $elapsed = 0;
        $attempt = 0;

        while (true) {
            $result = $fetch();

            if ($isDone($result)) {
                return ['result' => $result, 'done' => true];
            }

            if ($elapsed >= $timeout) {
                return ['result' => $result, 'done' => false];
            }

            $delay = min(self::DELAYS[min($attempt, count(self::DELAYS) - 1)], $timeout - $elapsed);
            Sleep::for($delay)->seconds();
            $elapsed += $delay;
            $attempt++;
        }
    }
}
