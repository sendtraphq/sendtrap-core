<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\Workspace;
use Sendtrap\Core\Contracts\WorkspaceEntitlements;

/**
 * Trivial Entitlements reference binding for the package's own Testbench
 * suite (§5.3): every workspace gets `UnlimitedWorkspaceEntitlements`.
 * Never the package's shipped default — this package has no default
 * Entitlements binding at all, by design (each host binds its own).
 */
class UnlimitedEntitlements implements Entitlements
{
    public function for(Workspace $workspace): WorkspaceEntitlements
    {
        return new UnlimitedWorkspaceEntitlements;
    }
}
