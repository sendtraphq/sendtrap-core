<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Contracts\Workspace;

/**
 * Trivial UsageMeter reference binding for the package's own Testbench
 * suite (§5.3): sends/forwards are always allowed and never metered,
 * storage usage is always zero and never exceeded. Never the package's
 * shipped default — this package has no default UsageMeter binding at all,
 * by design (each host binds its own).
 */
class UnlimitedUsageMeter implements UsageMeter
{
    public function checkSend(Workspace $workspace): ?string
    {
        return null;
    }

    public function recordSend(Workspace $workspace): void {}

    public function summary(Workspace $workspace): array
    {
        return [
            'per_minute' => null,
            'per_month' => null,
            'month_usage' => 0,
            'pct' => 0,
            'recent_block' => null,
        ];
    }

    public function canForward(Workspace $workspace): bool
    {
        return true;
    }

    public function recordForward(Workspace $workspace): void {}

    public function currentStorageBytes(Workspace $workspace): int
    {
        return 0;
    }

    public function wouldExceedStorage(Workspace $workspace, int $incomingBytes): bool
    {
        return false;
    }
}
