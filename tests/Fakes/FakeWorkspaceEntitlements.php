<?php

namespace Sendtrap\Core\Tests\Fakes;

use Sendtrap\Core\Contracts\WorkspaceEntitlements;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (c), "New tests required"): a
 * configurable WorkspaceEntitlements test double, paired with
 * FakeEntitlements. Every limit defaults to unlimited/enabled (matching
 * UnlimitedWorkspaceEntitlements) unless overridden via a named constructor
 * argument — this is what replaces a bucket (c) test's
 * `config(['billing.plans.free.limits.X' => …])` call: the package has no
 * `billing.php` config tree, so a test that needs a specific limit binds a
 * fake directly instead.
 */
class FakeWorkspaceEntitlements implements WorkspaceEntitlements
{
    public function __construct(
        private readonly ?int $sendsPerMinute = null,
        private readonly ?int $sendsPerMonth = null,
        private readonly ?int $forwardsPerMonth = null,
        private readonly ?int $emailSizeBytes = null,
        private readonly ?int $projectsLimit = null,
        private readonly ?int $inboxesLimit = null,
        private readonly ?int $usersLimit = null,
        private readonly ?int $messagesPerInbox = null,
        private readonly ?int $retentionDays = null,
        private readonly ?int $storageBytesLimit = null,
        private readonly ?int $apiRequestsPerMinute = null,
        private readonly bool $hasApiAccess = true,
        private readonly bool $hasSupport = true,
        private readonly bool $hasHtmlCheckApi = true,
    ) {}

    public function sendsPerMinute(): ?int
    {
        return $this->sendsPerMinute;
    }

    public function sendsPerMonth(): ?int
    {
        return $this->sendsPerMonth;
    }

    public function forwardsPerMonth(): ?int
    {
        return $this->forwardsPerMonth;
    }

    public function emailSizeBytes(): ?int
    {
        return $this->emailSizeBytes;
    }

    public function projectsLimit(): ?int
    {
        return $this->projectsLimit;
    }

    public function inboxesLimit(): ?int
    {
        return $this->inboxesLimit;
    }

    public function usersLimit(): ?int
    {
        return $this->usersLimit;
    }

    public function messagesPerInbox(): ?int
    {
        return $this->messagesPerInbox;
    }

    public function retentionDays(): ?int
    {
        return $this->retentionDays;
    }

    public function storageBytesLimit(): ?int
    {
        return $this->storageBytesLimit;
    }

    public function apiRequestsPerMinute(): ?int
    {
        return $this->apiRequestsPerMinute;
    }

    public function hasApiAccess(): bool
    {
        return $this->hasApiAccess;
    }

    public function hasSupport(): bool
    {
        return $this->hasSupport;
    }

    public function hasHtmlCheckApi(): bool
    {
        return $this->hasHtmlCheckApi;
    }

    public function within(string $name, int $current): bool
    {
        $limit = match ($name) {
            'projects' => $this->projectsLimit,
            'inboxes' => $this->inboxesLimit,
            'users' => $this->usersLimit,
            default => null,
        };

        return $limit === null || $current < $limit;
    }
}
