<?php

namespace Sendtrap\Core\Support;

use Sendtrap\Core\Contracts\LegacyOwnershipFallback;
use Sendtrap\Core\Models\Inbox;

/**
 * The package's shipped default LegacyOwnershipFallback: a host with no
 * pre-Workspace compatibility window (Community — its one workspace is
 * created at install time, before any project can exist) has no legacy
 * owners to enforce against, so every answer is the contract's "no limit"
 * answer.
 *
 * active() returns true — "permitted", not "in use": such a host has
 * no kill-switch config to read, and the value is moot anyway since no call
 * site's own derived trigger ever fires for a host with no pre-Workspace
 * state. Bound via bindIf in SendtrapCoreServiceProvider::register() so a
 * host binding (Cloud's CloudLegacyOwnershipFallback) wins when present.
 */
class NullLegacyOwnershipFallback implements LegacyOwnershipFallback
{
    public function active(): bool
    {
        return true;
    }

    public function emailSizeLimitBytes(?Inbox $inbox): ?int
    {
        return null;
    }

    public function checkSend(?Inbox $inbox): ?string
    {
        return null;
    }

    public function recordSend(?Inbox $inbox): void
    {
        // No legacy owner to record against — deliberate no-op.
    }

    public function wouldExceedStorage(?Inbox $inbox, int $incomingBytes): bool
    {
        return false;
    }

    public function canForward(?Inbox $inbox): bool
    {
        return true;
    }

    public function recordForward(?Inbox $inbox): void
    {
        // No legacy owner to record against — deliberate no-op.
    }

    public function allowedIpsFallback(?Inbox $inbox): array
    {
        return [];
    }

    public function pruneLegacyOwned(bool $dryRun): int
    {
        return 0;
    }
}
