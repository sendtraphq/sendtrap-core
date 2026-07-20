<?php

namespace Sendtrap\Core\Contracts;

/**
 * Records and queries sending, forwarding and storage usage for a
 * workspace, without assuming the backing store.
 *
 * Community may use local database/cache counters. Cloud uses tenant-scoped
 * durable usage and billing/abuse telemetry. Mirrors the mechanism surface
 * of the Cloud host's SendingLimiter support class.
 */
interface UsageMeter
{
    /**
     * Whether a send should be allowed right now. Returns null when
     * allowed, or the block reason: 'rate' (per-minute limit) or 'quota'
     * (monthly limit). Callers must not accept the send when a non-null
     * reason is returned.
     */
    public function checkSend(Workspace $workspace): ?string;

    /**
     * Record an accepted send (increments both the per-minute window
     * counter and the monthly counter).
     */
    public function recordSend(Workspace $workspace): void;

    /**
     * A usage summary for surfacing limits in the UI.
     *
     * @return array{per_minute: ?int, per_month: ?int, month_usage: int, pct: int, recent_block: ?string}
     */
    public function summary(Workspace $workspace): array;

    /**
     * Whether the workspace may auto-forward another message this
     * calendar month.
     */
    public function canForward(Workspace $workspace): bool;

    /**
     * Record an accepted auto-forward.
     */
    public function recordForward(Workspace $workspace): void;

    /**
     * Current total storage usage for the workspace, in bytes, across all
     * messages and attachments.
     */
    public function currentStorageBytes(Workspace $workspace): int;

    /**
     * Whether accepting `$incomingBytes` more would push the workspace
     * over its storage limit. Always false when the workspace has no
     * storage limit.
     *
     * @deprecated Plan 01a: implementations historically also *counted* the
     * accepted bytes as a side effect of this check — a non-atomic
     * read-modify-write that loses updates under concurrent workers.
     * Admission decisions belong on the StorageQuota contract's
     * reserve/commit/release lifecycle; ingestion no longer calls this.
     * Retained (and still answered) for UI/reporting callers until the next
     * major release.
     */
    public function wouldExceedStorage(Workspace $workspace, int $incomingBytes): bool;
}
