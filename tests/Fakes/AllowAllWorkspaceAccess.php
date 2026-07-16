<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\Workspace;
use Sendtrap\Core\Contracts\WorkspaceAccess;

/**
 * Trivial WorkspaceAccess reference binding for the package's own Testbench
 * suite (§5.3): every user may view and manage every workspace. Never the
 * package's shipped default — this package has no default WorkspaceAccess
 * binding at all, by design (each host binds its own).
 */
class AllowAllWorkspaceAccess implements WorkspaceAccess
{
    public function canView(object $user, Workspace $workspace): bool
    {
        return true;
    }

    public function canManage(object $user, Workspace $workspace): bool
    {
        return true;
    }
}
