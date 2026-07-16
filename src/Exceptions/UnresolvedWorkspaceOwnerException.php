<?php

namespace Sendtrap\Core\Exceptions;

use RuntimeException;
use Sendtrap\Core\Contracts\Workspace;

/**
 * Thrown by an Entitlements/UsageMeter implementation when a *resolved but
 * orphaned* Workspace — one that has a row, but no owning host record — is
 * passed in. The host-agnostic parent for any thin host-side subclass
 * carrying that host's own alerting/report() logic.
 *
 * Core call sites catch this as the second trigger for the
 * LegacyOwnershipFallback path (the first being `$workspace === null`
 * outright, i.e. project.workspace_id still null) — see the gate ×
 * active() matrix in LegacyOwnershipFallback's contract docblock.
 *
 * Phase 3b slice 4: $workspace is nullable — the gate × flag matrix's
 * fail-loud branches (a call site that derived it needs the fallback while
 * active() === false) throw this very exception with NO workspace at all,
 * because a workspace that never resolved is precisely why they are
 * throwing. Adapters throwing over a resolved-but-orphaned workspace keep
 * passing the instance, as before.
 */
class UnresolvedWorkspaceOwnerException extends RuntimeException
{
    public function __construct(public readonly ?Workspace $workspace = null, ?string $message = null)
    {
        parent::__construct(
            $message ?? ($workspace
                ? "Workspace [{$workspace->id()}] could not be resolved to a concrete owner."
                : 'No workspace owner could be resolved.')
        );
    }
}
