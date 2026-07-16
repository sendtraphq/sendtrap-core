<?php

namespace Sendtrap\Core\Contracts;

/**
 * Resolves the current Workspace for web/API/console operations, and looks
 * up a Workspace by an inbox credential.
 *
 * Community returns the single installed workspace regardless of the
 * caller. Cloud resolves the authenticated user's current Team's workspace,
 * or the workspace owning a given inbox's credential.
 *
 * Core jobs and queued work should keep carrying explicit workspace/inbox
 * IDs rather than depending on this ambient context — it exists for web/API
 * request-time resolution, not for background work.
 */
interface WorkspaceContext
{
    /**
     * The ambient current workspace for this request/session, if any.
     */
    public function current(): ?Workspace;

    /**
     * The workspace that owns the given inbox, by inbox ID. Used by
     * credential/token-scoped paths (e.g. rate limiting an inbox-token
     * request) that must not depend on ambient web/session state.
     */
    public function forInboxId(int $inboxId): ?Workspace;

    /**
     * Every workspace known to this host, lazily. Used for host-wide
     * maintenance operations (e.g. retention pruning) that must visit each
     * workspace in turn without loading them all into memory at once.
     *
     * @return iterable<Workspace>
     */
    public function all(): iterable;
}
