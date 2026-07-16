<?php

namespace Sendtrap\Core\Policies;

use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Models\Inbox;

/**
 * Plan 06 Phase 2 (§5.1 item 1): authorization resolves through the core
 * WorkspaceAccess contract against the inbox's project's Workspace, not
 * Team membership directly. A null workspace (project not yet backfilled)
 * denies — never passed into the non-nullable contract parameter (§5.0.1
 * row 1: deny-by-default, never "no scope").
 *
 * Plan 06 Phase 3b slice 8 (§1.6, H-3(a) stage 2): moved from the host's
 * former app/Policies/InboxPolicy.php, unedited apart from the $user
 * parameter type — WorkspaceAccess::canView()/canManage() already type
 * their own $user parameter as plain `object`
 * (Sendtrap\Core\Contracts\WorkspaceAccess) for exactly this reason: the
 * package has no host User model to type against. Gate never relies on the
 * parameter's declared type to pick an overload, so this is
 * behavior-neutral.
 */
class InboxPolicy
{
    public function view(object $user, Inbox $inbox): bool
    {
        if (! $workspace = $inbox->project->workspace) {
            return false;
        }

        return app(WorkspaceAccess::class)->canView($user, $workspace);
    }

    public function update(object $user, Inbox $inbox): bool
    {
        if (! $workspace = $inbox->project->workspace) {
            return false;
        }

        return app(WorkspaceAccess::class)->canManage($user, $workspace);
    }

    public function delete(object $user, Inbox $inbox): bool
    {
        if (! $workspace = $inbox->project->workspace) {
            return false;
        }

        return app(WorkspaceAccess::class)->canManage($user, $workspace);
    }
}
