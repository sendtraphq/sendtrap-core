<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\StorageQuota;
use Sendtrap\Core\Contracts\Workspace;
use Sendtrap\Core\Storage\StorageAdmission;
use Sendtrap\Core\Storage\StorageReservation;
use Throwable;

/**
 * Recording StorageQuota test double (Plan 01a): answers every reserve()
 * with the configured admission and hands out sequential accountable
 * tokens, so a test can assert the full reserve/commit/release lifecycle —
 * which operations ran, in what order, with which byte counts — without a
 * Redis (or any) backend.
 */
class FakeStorageQuota implements StorageQuota
{
    /** @var list<array{workspace: int|string, bytes: int}> */
    public array $reserves = [];

    /** @var list<array{workspace: int|string, bytes: int}> */
    public array $removals = [];

    /** @var list<array{token: ?string, stored: int, removed: int}> */
    public array $commits = [];

    /** @var list<?string> */
    public array $releases = [];

    protected int $nextToken = 0;

    public function __construct(
        public StorageAdmission $admission = StorageAdmission::Allowed,
        public ?Throwable $throwOnRemoval = null,
    ) {}

    public function reserve(Workspace $workspace, int $maximumBytes): StorageReservation
    {
        $this->reserves[] = ['workspace' => $workspace->id(), 'bytes' => $maximumBytes];

        return match ($this->admission) {
            StorageAdmission::Allowed => StorageReservation::allowed($workspace->id(), 'op-'.++$this->nextToken, $maximumBytes),
            StorageAdmission::Blocked => StorageReservation::blocked(),
            StorageAdmission::Unlimited => StorageReservation::unlimited(),
            StorageAdmission::Retry => StorageReservation::retry(),
        };
    }

    public function beginRemoval(Workspace $workspace, int $maximumBytes): StorageReservation
    {
        if ($this->throwOnRemoval) {
            throw $this->throwOnRemoval;
        }

        $this->removals[] = ['workspace' => $workspace->id(), 'bytes' => $maximumBytes];

        return StorageReservation::allowed($workspace->id(), 'rm-'.++$this->nextToken, 0);
    }

    public function commit(StorageReservation $reservation, int $storedBytes, int $removedBytes = 0): void
    {
        $this->commits[] = ['token' => $reservation->token, 'stored' => $storedBytes, 'removed' => $removedBytes];
    }

    public function release(StorageReservation $reservation): void
    {
        $this->releases[] = $reservation->token;
    }
}
