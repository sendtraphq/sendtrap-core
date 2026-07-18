<?php

namespace Sendtrap\Core\Support;

use Closure;
use Sendtrap\Core\Contracts\MessageWaiter;

/**
 * The default MessageWaiter: busy-poll with backoff, same cadence as the
 * /assert path (250ms doubling toward 1s). Occupies one HTTP worker for at
 * most the (already clamped) timeout — which is why the /expect route sits
 * behind the tight `inbox-api-wait` limiter.
 */
class PollingMessageWaiter implements MessageWaiter
{
    public function wait(int $timeoutMs, Closure $poll): bool
    {
        if ($poll()) {
            return true;
        }

        $deadline = microtime(true) + $timeoutMs / 1000;
        $intervalMicroseconds = 250_000;

        while (microtime(true) < $deadline) {
            usleep((int) min($intervalMicroseconds, max(1, ($deadline - microtime(true)) * 1_000_000)));
            $intervalMicroseconds = min((int) ($intervalMicroseconds * 1.5), 1_000_000);

            if ($poll()) {
                return true;
            }
        }

        return false;
    }
}
