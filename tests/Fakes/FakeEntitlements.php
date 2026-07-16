<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\Workspace;
use Sendtrap\Core\Contracts\WorkspaceEntitlements;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (c), "New tests required" — the
 * `FakeEntitlements`/`FakeUsageMeter` pair §5.1 mandates for the heavy-
 * rework bucket): every workspace resolves to the one FakeWorkspaceEntitlements
 * instance this was constructed with — configurable per-test via
 * FakeWorkspaceEntitlements' own constructor args, unlike
 * UnlimitedEntitlements which always returns the fixed unlimited default.
 */
class FakeEntitlements implements Entitlements
{
    public function __construct(private readonly WorkspaceEntitlements $entitlements) {}

    public function for(Workspace $workspace): WorkspaceEntitlements
    {
        return $this->entitlements;
    }
}
