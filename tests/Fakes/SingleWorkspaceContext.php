<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\Workspace;
use Sendtrap\Core\Contracts\WorkspaceContext;

/**
 * Trivial WorkspaceContext reference binding for the package's own
 * Testbench suite (§5.3): always resolves to the one fixed Workspace it was
 * built with, regardless of caller/inbox. Never the package's shipped
 * default — this package has no default WorkspaceContext binding at all,
 * by design (each host binds its own).
 */
class SingleWorkspaceContext implements WorkspaceContext
{
    public function __construct(private readonly Workspace $workspace) {}

    public function current(): ?Workspace
    {
        return $this->workspace;
    }

    public function forInboxId(int $inboxId): ?Workspace
    {
        return $this->workspace;
    }

    public function all(): iterable
    {
        yield $this->workspace;
    }
}
