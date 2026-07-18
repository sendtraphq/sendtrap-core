<?php

namespace Sendtrap\Core\Contracts;

use Closure;

/**
 * The transport-neutral wait seam behind /expect (Plan 02 §Architecture).
 *
 * The default binding busy-polls in-process; a future broker/outbox
 * notification implementation (Plan 10) can replace it without any public
 * API change — the controller only ever asks "re-check until this returns
 * true or the budget runs out".
 */
interface MessageWaiter
{
    /**
     * Invoke $poll immediately, then repeatedly until it returns true or
     * $timeoutMs elapses. Returns the final poll result.
     *
     * @param  Closure(): bool  $poll
     */
    public function wait(int $timeoutMs, Closure $poll): bool;
}
