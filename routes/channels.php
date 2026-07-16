<?php

use Illuminate\Support\Facades\Broadcast;
use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Models\Inbox;

/*
 * Plan 06 Phase 3b slice 7 (§1.6/§1.6.2): moved unedited from the host's
 * routes/channels.php:11-22 (the App.Models.User.{id} channel stays
 * host-side — it's about the host's own User model, not a core concept).
 * Registered directly from SendtrapCoreServiceProvider::boot() via a plain
 * `require`, not $this->loadRoutesFrom() — this file is a set of direct
 * Broadcast::channel() calls, not Router-loaded route definitions, so it
 * doesn't need (or want) the route-cache-skip guard loadRoutesFrom applies.
 *
 * Only members of the inbox's workspace may subscribe to its live message
 * stream. Plan 06 Phase 2 (§5.1 item 2): resolved via WorkspaceAccess
 * against the inbox's project's workspace; a null workspace (project not
 * yet backfilled) is a deny — Laravel treats a falsy channel-authorization
 * result as a 403 on the broadcasting auth endpoint (§5.0.1 row 2).
 */
Broadcast::channel('inbox.{inbox}', function ($user, Inbox $inbox) {
    $workspace = $inbox->project->workspace;

    return $workspace ? app(WorkspaceAccess::class)->canView($user, $workspace) : false;
});
