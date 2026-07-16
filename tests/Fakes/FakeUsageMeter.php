<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Contracts\Workspace;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (c), "New tests required"): a
 * configurable UsageMeter test double, paired with FakeEntitlements. Every
 * knob defaults to the same "everything allowed" shape as
 * UnlimitedUsageMeter unless overridden via a named constructor argument —
 * this is what replaces a bucket (c) test's manual quota-consumption dance
 * (e.g. `(new SendingLimiter)->hitForward($team)` to force a decision) when
 * the test only cares about the *decision*, not the counter mechanics.
 * recordSend()/recordForward() calls are counted so a test can still assert
 * "was this recorded" without needing a real durable counter.
 */
class FakeUsageMeter implements UsageMeter
{
    public int $sendsRecorded = 0;

    public int $forwardsRecorded = 0;

    public function __construct(
        private readonly ?string $checkSend = null,
        private readonly bool $canForward = true,
        private readonly bool $wouldExceedStorage = false,
        private readonly int $currentStorageBytes = 0,
        private readonly array $summary = [
            'per_minute' => null,
            'per_month' => null,
            'month_usage' => 0,
            'pct' => 0,
            'recent_block' => null,
        ],
    ) {}

    public function checkSend(Workspace $workspace): ?string
    {
        return $this->checkSend;
    }

    public function recordSend(Workspace $workspace): void
    {
        $this->sendsRecorded++;
    }

    public function summary(Workspace $workspace): array
    {
        return $this->summary;
    }

    public function canForward(Workspace $workspace): bool
    {
        return $this->canForward;
    }

    public function recordForward(Workspace $workspace): void
    {
        $this->forwardsRecorded++;
    }

    public function currentStorageBytes(Workspace $workspace): int
    {
        return $this->currentStorageBytes;
    }

    public function wouldExceedStorage(Workspace $workspace, int $incomingBytes): bool
    {
        return $this->wouldExceedStorage;
    }
}
