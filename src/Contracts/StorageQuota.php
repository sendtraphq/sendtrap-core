<?php

namespace Sendtrap\Core\Contracts;

use Sendtrap\Core\Storage\StorageReservation;

/**
 * Atomic storage-quota admission and accounting for a workspace (Plan 01a).
 *
 * Separate from UsageMeter on purpose: send/forward metering is a
 * fire-and-forget counter, while storage admission is a two-phase
 * reservation whose decision and byte-accounting must be a single atomic
 * operation under concurrent workers. UsageMeter::wouldExceedStorage()'s
 * read-modify-write side effect is deprecated in favor of this contract.
 *
 * Lifecycle for ingestion:
 *
 *   1. reserve() the maximum prospective bytes BEFORE writing files/rows;
 *   2. on success, persist; then commit() with the bytes actually stored
 *      (raw source + successfully persisted attachments) and any bytes
 *      retention removed inside the same unit of work;
 *   3. any failure before commit release()s the reservation.
 *
 * Lifecycle for deletion/pruning:
 *
 *   1. beginRemoval() BEFORE mutating the database;
 *   2. commit() the exact removed bytes (as $removedBytes, with
 *      $storedBytes = 0) only after the deletion succeeded.
 *
 * A reservation whose result is not accountable() (unlimited plans,
 * blocked/retry decisions, implementations without per-operation tracking)
 * makes commit()/release() no-ops — callers follow the same lifecycle
 * unconditionally.
 *
 * Implementations must treat backend unavailability as a loud, retryable
 * failure (throw), never as a silent "allowed" or "blocked" decision.
 */
interface StorageQuota
{
    /**
     * Atomically decide whether `$maximumBytes` more may be stored and, if
     * so, reserve them. Returns an allowed, blocked, retry (admission
     * temporarily paused for reconciliation — requeue, don't drop) or
     * unlimited result carrying an opaque reservation token.
     */
    public function reserve(Workspace $workspace, int $maximumBytes): StorageReservation;

    /**
     * Register an intent to remove up to `$maximumBytes` (message +
     * attachment sizes) ahead of a deletion. The counter is only reduced by
     * the follow-up commit(); an abandoned removal operation expires into
     * reconciliation instead of guessing.
     */
    public function beginRemoval(Workspace $workspace, int $maximumBytes): StorageReservation;

    /**
     * Finalize an operation with the net stored delta: `$storedBytes`
     * actually persisted minus `$removedBytes` actually deleted. For a
     * removal operation pass `$storedBytes = 0`. Idempotent by reservation
     * token.
     */
    public function commit(StorageReservation $reservation, int $storedBytes, int $removedBytes = 0): void;

    /**
     * Cancel a failed attempt, returning its reserved bytes. Idempotent by
     * reservation token.
     */
    public function release(StorageReservation $reservation): void;
}
