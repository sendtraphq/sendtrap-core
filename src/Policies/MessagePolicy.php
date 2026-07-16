<?php

namespace Sendtrap\Core\Policies;

use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Models\Message;

/**
 * Plan 06 Phase 2 (§5.1 item 1): authorization resolves through the core
 * WorkspaceAccess contract against the message's inbox's project's
 * Workspace, not Team membership directly. A null workspace (project not
 * yet backfilled) denies — never passed into the non-nullable contract
 * parameter (§5.0.1 row 1: deny-by-default, never "no scope").
 *
 * Plan 06 Phase 3b slice 8 (§1.6, H-3(a) stage 2): moved from the host's
 * former app/Policies/MessagePolicy.php, unedited apart from the $user
 * parameter type — see Sendtrap\Core\Policies\InboxPolicy's docblock for
 * why.
 */
class MessagePolicy
{
    public function view(object $user, Message $message): bool
    {
        if (! $workspace = $message->inbox->project->workspace) {
            return false;
        }

        return app(WorkspaceAccess::class)->canView($user, $workspace);
    }

    public function delete(object $user, Message $message): bool
    {
        if (! $workspace = $message->inbox->project->workspace) {
            return false;
        }

        return app(WorkspaceAccess::class)->canManage($user, $workspace);
    }
}
