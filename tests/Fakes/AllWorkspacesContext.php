<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Models\Workspace;

/**
 * A WorkspaceContext reference binding that iterates every row of the real
 * Eloquent `Sendtrap\Core\Models\Workspace`, for exercising
 * `Sendtrap\Core\Console\Commands\PruneMessages::pruneByRetention()`'s
 * workspace-rooted outer loop (`app(WorkspaceContext::class)->all()`,
 * src/Console/Commands/PruneMessages.php:97) against
 * more than one workspace in the same run. `SingleWorkspaceContext` — the
 * package's own Testbench default — only ever yields the one fixed
 * instance it was built with, which can't drive a multi-workspace or
 * orphan-skip scenario.
 */
class AllWorkspacesContext implements WorkspaceContext
{
    public function current(): ?WorkspaceContract
    {
        return Workspace::first();
    }

    public function forInboxId(int $inboxId): ?WorkspaceContract
    {
        return Workspace::whereHas(
            'projects.inboxes',
            fn ($query) => $query->where('id', $inboxId),
        )->first();
    }

    public function all(): iterable
    {
        return Workspace::all();
    }
}
