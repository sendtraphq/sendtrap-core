<?php

namespace Sendtrap\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sendtrap\Core\Storage\StorageReservation;
use Sendtrap\Core\Storage\UsageMeterStorageQuota;
use Sendtrap\Core\Tests\Fakes\FakeUsageMeter;
use Sendtrap\Core\Tests\Fakes\FakeWorkspace;

/**
 * The package-default StorageQuota is a pure compatibility shim over the
 * host's UsageMeter binding: same decisions as pre-Plan-01a ingestion, no
 * per-operation tracking, so commit/release/removal are structural no-ops.
 */
class UsageMeterStorageQuotaTest extends TestCase
{
    public function test_reserve_blocks_when_the_meter_reports_exceeded(): void
    {
        $quota = new UsageMeterStorageQuota(new FakeUsageMeter(wouldExceedStorage: true));

        $reservation = $quota->reserve(new FakeWorkspace, 1_000);

        $this->assertTrue($reservation->isBlocked());
        $this->assertFalse($reservation->accountable());
    }

    public function test_reserve_allows_when_the_meter_reports_headroom(): void
    {
        $quota = new UsageMeterStorageQuota(new FakeUsageMeter(wouldExceedStorage: false));

        $reservation = $quota->reserve(new FakeWorkspace, 1_000);

        $this->assertTrue($reservation->isAllowed());
        $this->assertFalse($reservation->shouldRetry());

        // Not accountable: the meter already counted at reserve() (its own
        // deprecated side effect); commit/release must be safe no-ops.
        $this->assertFalse($reservation->accountable());
        $quota->commit($reservation, 900);
        $quota->release($reservation);
    }

    public function test_removals_are_untracked(): void
    {
        $quota = new UsageMeterStorageQuota(new FakeUsageMeter);

        $operation = $quota->beginRemoval(new FakeWorkspace, 5_000);

        $this->assertTrue($operation->isUnlimited());
        $this->assertFalse($operation->accountable());
        $quota->commit($operation, 0, 5_000);
    }

    public function test_reservation_value_object_statuses(): void
    {
        $this->assertTrue(StorageReservation::unlimited()->isAllowed());
        $this->assertFalse(StorageReservation::unlimited()->accountable());
        $this->assertTrue(StorageReservation::retry()->shouldRetry());
        $this->assertFalse(StorageReservation::retry()->isAllowed());
        $this->assertTrue(StorageReservation::blocked()->isBlocked());
        $this->assertTrue(StorageReservation::allowed(7, 'tok', 123)->accountable());
        $this->assertSame(123, StorageReservation::allowed(7, 'tok', 123)->reservedBytes);
    }
}
