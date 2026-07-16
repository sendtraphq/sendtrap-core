<?php

namespace Sendtrap\Core\Contracts;

/**
 * Feature availability and numeric limits for a single workspace, resolved
 * through Entitlements::for(). Mirrors the public getter surface of the
 * Cloud host's TeamPlan support class, so both hosts can express identical
 * semantics.
 *
 * All numeric limits are `?int`; null means unlimited. All feature flags
 * are plain bool. Core services ask this contract about capabilities and
 * limits — they never import Cashier or TeamPlan directly.
 */
interface WorkspaceEntitlements
{
    /**
     * Sending rate ceiling, per minute. Always active (abuse control, not a
     * paywall gate). Null = unlimited.
     */
    public function sendsPerMinute(): ?int;

    /**
     * Sending quota, per calendar month. Always active. Null = unlimited.
     */
    public function sendsPerMonth(): ?int;

    /**
     * Auto-forwarding quota, per calendar month. Always active. Null =
     * unlimited.
     */
    public function forwardsPerMonth(): ?int;

    /**
     * Per-message size cap, in bytes. Always active. Null = unlimited.
     */
    public function emailSizeBytes(): ?int;

    /**
     * Maximum number of projects. Advisory (feature-count) limit. Null =
     * unlimited.
     */
    public function projectsLimit(): ?int;

    /**
     * Maximum number of inboxes. Advisory limit. Null = unlimited.
     */
    public function inboxesLimit(): ?int;

    /**
     * Maximum number of users. Advisory limit. Null = unlimited.
     */
    public function usersLimit(): ?int;

    /**
     * Per-inbox message cap, used as the default/ceiling for an inbox's
     * max_messages. Always active. Null = unlimited.
     */
    public function messagesPerInbox(): ?int;

    /**
     * Age-based message retention, in days. Always active (disk-safety
     * control). Null = unlimited.
     */
    public function retentionDays(): ?int;

    /**
     * Workspace-wide storage cap, in bytes, across all messages and
     * attachments. Always active. Null = unlimited.
     */
    public function storageBytesLimit(): ?int;

    /**
     * Requests/minute ceiling for the token-authenticated inbox API.
     * Always active (abuse control). Null = unlimited.
     */
    public function apiRequestsPerMinute(): ?int;

    /**
     * Whether the workspace has API access at all.
     */
    public function hasApiAccess(): bool;

    /**
     * Whether the workspace has access to support channels.
     */
    public function hasSupport(): bool;

    /**
     * Whether the workspace has access to HTML Check via the public API.
     */
    public function hasHtmlCheckApi(): bool;

    /**
     * Whether `$current` is within the named advisory feature-count limit.
     * Always true when the limit is null or when feature-limit enforcement
     * is off for this host. Named limits are the advisory getters above —
     * 'projects', 'inboxes', 'users' — and never the always-active ones
     * (message caps, retention, storage, rate limits), which apply
     * regardless of enforcement mode.
     */
    public function within(string $name, int $current): bool;
}
