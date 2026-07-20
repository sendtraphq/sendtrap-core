<?php

namespace Sendtrap\Core\Storage;

use Sendtrap\Core\Contracts\StorageQuota;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Contracts\Workspace;

/**
 * The package-default StorageQuota: a compatibility shim over the host's
 * UsageMeter binding, preserving pre-Plan-01a behavior for hosts that have
 * not bound an atomic implementation of their own.
 *
 * reserve() maps UsageMeter::wouldExceedStorage()'s boolean straight to
 * blocked/allowed — including any counting side effect that method has
 * (deprecated, but still the meter's own concern here). Nothing is tracked
 * per operation, so the returned reservations are not accountable() and
 * commit()/release()/beginRemoval() are no-ops: exactly today's semantics,
 * where deletions become visible at the meter's next recompute rather than
 * immediately.
 *
 * Hosts wanting the Plan 01a accuracy contract (atomic admission, exact
 * commit/removal deltas, fixed reconciliation) bind their own
 * implementation; Cloud binds a Redis-backed one.
 */
class UsageMeterStorageQuota implements StorageQuota
{
    public function __construct(protected UsageMeter $meter) {}

    public function reserve(Workspace $workspace, int $maximumBytes): StorageReservation
    {
        return $this->meter->wouldExceedStorage($workspace, $maximumBytes)
            ? StorageReservation::blocked()
            : StorageReservation::allowed();
    }

    public function beginRemoval(Workspace $workspace, int $maximumBytes): StorageReservation
    {
        return StorageReservation::unlimited();
    }

    public function commit(StorageReservation $reservation, int $storedBytes, int $removedBytes = 0): void
    {
        // No per-operation tracking: the meter already counted at reserve().
    }

    public function release(StorageReservation $reservation): void
    {
        // No per-operation tracking: an over-count self-heals at the
        // meter's next storage recompute.
    }
}
