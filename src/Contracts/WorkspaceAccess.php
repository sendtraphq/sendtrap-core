<?php

namespace Sendtrap\Core\Contracts;

/**
 * Answers whether a user may view or manage a workspace and the resources
 * (projects, inboxes, messages) that belong to it.
 *
 * Core policies delegate to this contract instead of calling
 * `belongsToTeam()` or returning an unconditional true.
 *
 * Community implements global owner/member/viewer roles: viewers can view
 * but not manage, members and owners can manage. Cloud delegates to Team
 * membership/roles and tenant policies. Both levels currently collapse to
 * the same membership check in Cloud (see app/Cloud/CloudWorkspaceAccess)
 * — kept distinct here so Community can split them.
 *
 * $user is typed as `object` rather than a concrete User model so this
 * contract has no dependency on either host's user class.
 */
interface WorkspaceAccess
{
    /**
     * Whether the user may view the workspace and its resources.
     */
    public function canView(object $user, Workspace $workspace): bool;

    /**
     * Whether the user may create/update/delete resources within the
     * workspace (projects, inboxes, messages) and manage
     * instance-sensitive operations scoped to it.
     */
    public function canManage(object $user, Workspace $workspace): bool;
}
