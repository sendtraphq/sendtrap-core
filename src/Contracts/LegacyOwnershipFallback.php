<?php

namespace Sendtrap\Core\Contracts;

use Sendtrap\Core\Models\Inbox;

/**
 * A host-bindable escape hatch for enforcement against a message/session
 * whose Inbox->project->workspace could not be resolved to a concrete
 * owner. Exists only for the duration of a host's own pre-Workspace
 * migration compatibility window (see the host's own ownership-migration
 * design, e.g. Cloud's Phase 2 Team→Workspace backfill).
 *
 * Every enforcement
 * method's need-to-fire is DERIVED per call site, never centrally
 * configured — a null Workspace (SmtpServer, ProcessIncomingMessage) or
 * PruneMessages' own `Project::whereNull('workspace_id')->exists()`
 * mechanical gate is what decides "this record needs the fallback", full
 * stop. active() is not consulted to make that decision. active() is a
 * narrower thing: an operator-facing kill switch, consulted ONLY after a
 * call site has already derived that it needs the fallback, to decide
 * whether the fallback may actually run (true, the default — proceed) or
 * must instead fail loud (false — an operator has explicitly asserted
 * "the fallback should not be needed anymore, treat any record that still
 * needs it as an error, not a silently-tolerated edge case"). See §3.3/§3.4
 * for the exact gate × active() matrix per call site — it differs by call
 * site because "fail loud" means something different for a daemon
 * connection, a queued job, and a batch command.
 *
 * Community has no pre-Workspace state (its one workspace is created at
 * install time, before any project can exist), so no call site's own
 * derived trigger ever fires there — active() is never even reached, let
 * alone consulted, in practice. Community binds the package's default
 * no-op implementation regardless, for the same defense-in-depth reason
 * every other contract in this design gets a safe default.
 *
 * H-N1: every per-message method below accepts a NULLABLE $inbox. The
 * fallback path doesn't just resolve a null legacy owner from an
 * otherwise-solid Inbox — the Inbox row itself can be gone by the time
 * this contract is consulted (cascade-deleted mid-SMTP-session, exactly
 * like the owner that held it; see SmtpOrphanWorkspaceTest and §3.4's
 * sketch). A null $inbox and a non-null $inbox that resolves no legacy
 * owner are the SAME case from this contract's point of view: there is no
 * legacy owner left to check a limit against. Both MUST produce the
 * identical "no limit" answer — never a TypeError from a non-nullable
 * parameter, and never a deny (deny/tempfail is active()'s job alone, only
 * when active() === false; see §3.3's gate × flag matrix and §3.4).
 * recordSend()/recordForward() on either null case are no-ops.
 */
interface LegacyOwnershipFallback
{
    /**
     * Whether the fallback is currently PERMITTED to run, given a call
     * site has already independently determined it needs to (H-4). Not a
     * "do I have legacy records" query — callers derive that themselves.
     * Default true (permitted); an operator sets it false as a fail-loud
     * kill switch once they believe the fallback should no longer be
     * needed, to surface any remaining case loudly instead of silently
     * tolerating it.
     */
    public function active(): bool;

    /** Per-plan message size cap for the inbox's legacy owner, in bytes. Null = unlimited (also the answer for a null $inbox or an unresolvable owner — H-N1). */
    public function emailSizeLimitBytes(?Inbox $inbox): ?int;

    /** Send rate/quota check against the legacy owner. Returns null (allowed) | 'rate' | 'quota'. Null $inbox or unresolvable owner => null (H-N1). */
    public function checkSend(?Inbox $inbox): ?string;

    /** Record an accepted send against the legacy owner's counters. No-op for a null $inbox or unresolvable owner (H-N1). */
    public function recordSend(?Inbox $inbox): void;

    /** Whether accepting $incomingBytes more would exceed the legacy owner's storage cap. False (not exceeded) for a null $inbox or unresolvable owner (H-N1). */
    public function wouldExceedStorage(?Inbox $inbox, int $incomingBytes): bool;

    /** Whether the legacy owner may accept another auto-forward this calendar month. True (allowed) for a null $inbox or unresolvable owner (H-N1). */
    public function canForward(?Inbox $inbox): bool;

    /** Record an accepted auto-forward against the legacy owner's counters. No-op for a null $inbox or unresolvable owner (H-N1). */
    public function recordForward(?Inbox $inbox): void;

    /**
     * The legacy owner's account-tier IP allowlist — the last-resort tier
     * of Inbox::effectiveAllowedIps() when the workspace tier is
     * unresolvable (§3.3). Empty array = no restriction (also the answer
     * for a null $inbox or an unresolvable owner, matching H-N1's "no
     * limit" pattern for every other method on this contract).
     *
     * @return array<int, string>
     */
    public function allowedIpsFallback(?Inbox $inbox): array;

    /**
     * Run this host's entire legacy-owned prune sweep — its own outer
     * iteration, retention-limit resolution, and deletion — for as long as
     * active() is true. Returns the count of messages deleted (or that
     * would be deleted, under $dryRun). The package's own workspace-rooted
     * prune loop is skipped entirely for the run when this fires — never
     * both, never partial (carries Phase 2's §5.1 item 7 "mechanical gate"
     * semantics forward unchanged).
     */
    public function pruneLegacyOwned(bool $dryRun): int;
}
