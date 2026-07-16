<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\Workspace;

/**
 * A minimal, non-Eloquent implementation of the Workspace contract for the
 * package's own Testbench suite (§5.3) — deliberately not the Eloquent
 * `Sendtrap\Core\Models\Workspace` (which doesn't exist until Plan 06 Phase
 * 3b slice 2), so reference-binding tests can run before the models move.
 */
class FakeWorkspace implements Workspace
{
    public function __construct(
        private readonly int|string $id = 1,
        private readonly string $name = 'Test Workspace',
    ) {}

    public function id(): int|string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }
}
