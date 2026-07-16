<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\WorkspaceEntitlements;

/**
 * Trivial WorkspaceEntitlements reference binding for the package's own
 * Testbench suite (§5.3): every limit is unlimited (null), every feature
 * flag is enabled, `within()` always passes. Paired with
 * `UnlimitedEntitlements`.
 */
class UnlimitedWorkspaceEntitlements implements WorkspaceEntitlements
{
    public function sendsPerMinute(): ?int
    {
        return null;
    }

    public function sendsPerMonth(): ?int
    {
        return null;
    }

    public function forwardsPerMonth(): ?int
    {
        return null;
    }

    public function emailSizeBytes(): ?int
    {
        return null;
    }

    public function projectsLimit(): ?int
    {
        return null;
    }

    public function inboxesLimit(): ?int
    {
        return null;
    }

    public function usersLimit(): ?int
    {
        return null;
    }

    public function messagesPerInbox(): ?int
    {
        return null;
    }

    public function retentionDays(): ?int
    {
        return null;
    }

    public function storageBytesLimit(): ?int
    {
        return null;
    }

    public function apiRequestsPerMinute(): ?int
    {
        return null;
    }

    public function hasApiAccess(): bool
    {
        return true;
    }

    public function hasSupport(): bool
    {
        return true;
    }

    public function hasHtmlCheckApi(): bool
    {
        return true;
    }

    public function within(string $name, int $current): bool
    {
        return true;
    }
}
