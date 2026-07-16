<?php

namespace Sendtrap\Core\Contracts;

/**
 * A minimal, host-agnostic handle for a tenant boundary.
 *
 * Community has exactly one Workspace; Cloud has many, one per Team. This
 * contract intentionally carries no billing or membership behavior — see
 * WorkspaceAccess for authorization and Entitlements for limits/features.
 */
interface Workspace
{
    /**
     * The workspace's durable identifier.
     */
    public function id(): int|string;

    /**
     * A human-readable label for the workspace.
     */
    public function name(): string;
}
