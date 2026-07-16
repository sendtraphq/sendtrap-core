<?php

namespace Sendtrap\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\LegacyOwnershipFallback;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Exceptions\UnresolvedWorkspaceOwnerException;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Support\MessageStorage;
use Throwable;

/**
 * Age out messages past each workspace's plan retention window, and sweep
 * any on-disk message/attachment files that no longer have a matching DB
 * row (e.g. left behind by a cascade delete that bypassed Eloquent events).
 *
 * A legacy-owner (pre-Workspace) outer loop is deliberately absent here —
 * that logic belongs in a host's own LegacyOwnershipFallback binding
 * (pruneLegacyOwned()). This command's own mechanical gate
 * (Project::whereNull('workspace_id')->exists()) still decides, on every
 * invocation, whether the WHOLE run — outer loop included — delegates to
 * that binding instead (never partial, never mixing the workspace-rooted
 * loop and the legacy loop in the same run). The gate never reads active()
 * to decide whether it fires — active() is consulted only once the gate has
 * already fired, to decide proceed (true, default: run the whole legacy
 * sweep) vs. abort-loud (false: an operator has disabled the fallback while
 * lag still exists, an inconsistent operational state, so the entire run
 * aborts rather than silently under-pruning).
 */
class PruneMessages extends Command
{
    protected $signature = 'mail:prune {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete messages past their plan\'s retention window and sweep orphaned files';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->pruneByRetention($dryRun)) {
            return self::FAILURE;
        }

        $this->sweepOrphanedFiles($dryRun);

        return self::SUCCESS;
    }

    /**
     * Returns false when the run was aborted (gate fired, kill switch
     * disabled) — handle() must not proceed to the orphan sweep in that case
     * (§3.3: "abort the entire run", not merely skip the retention pass).
     */
    protected function pruneByRetention(bool $dryRun): bool
    {
        // Mechanical gate (§5.1 item 7, MEDIUM-3; §3.3 H-4): while ANY
        // project still lacks a workspace_id, the ENTIRE run — outer loop
        // included, per N-3, since the legacy-owner-chunking and
        // Workspace-iteration outer loops visit different row sets whenever
        // lag/orphans exist — falls back to the full pre-flip legacy path.
        // Self-enforcing on every invocation; a lagging project is pruned
        // via the fallback, never silently excluded from both paths.
        if (Project::whereNull('workspace_id')->exists()) {
            $fallback = app(LegacyOwnershipFallback::class);

            if (! $fallback->active()) {
                // Kill switch engaged while lag still exists: an
                // inconsistent operational state, not a "just skip it"
                // state — silently skipping would leave lagging tenants'
                // messages un-pruned indefinitely, a retention/compliance
                // gap, not merely cosmetic. Abort the entire run, loud.
                Log::critical('prune.legacy_fallback_disabled_with_lag');
                $this->error('Retention: unbackfilled projects exist but the legacy ownership fallback is disabled. Aborting the run.');

                return false;
            }

            $deleted = $fallback->pruneLegacyOwned($dryRun);
            $this->info(($dryRun ? '[dry-run] ' : '')."Retention: {$deleted} message(s) past their plan's retention window.");

            return true;
        }

        $deleted = 0;
        $orphans = 0;
        $failed = 0;

        foreach (app(WorkspaceContext::class)->all() as $workspace) {
            // Per-workspace failure isolation (MEDIUM-2): one tenant's
            // orphan/exception cannot halt the platform-wide prune run.
            try {
                $days = app(Entitlements::class)->for($workspace)->retentionDays();

                if ($days === null) {
                    continue; // unlimited retention on this plan
                }

                $cutoff = now()->subDays($days);

                Message::whereHas('inbox.project', fn ($q) => $q->where('workspace_id', $workspace->id()))
                    ->where('received_at', '<', $cutoff)
                    ->chunkById(200, function ($messages) use (&$deleted, $dryRun) {
                        $deleted += $messages->count();

                        if (! $dryRun) {
                            $messages->each->delete();
                        }
                    });
            } catch (UnresolvedWorkspaceOwnerException) {
                Log::warning('prune.orphan_workspace_skipped', ['workspace_id' => $workspace->id()]);
                $orphans++;

                continue;
            } catch (Throwable $e) {
                Log::error('prune.workspace_failed', [
                    'workspace_id' => $workspace->id(),
                    'error' => $e->getMessage(),
                ]);
                $failed++;

                continue;
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Retention: {$deleted} message(s) past their plan's retention window.");

        if ($orphans > 0) {
            // Expected to correlate with the audit's own orphan count
            // (§4.1 #3) — a discrepancy means an orphan appeared between
            // audit cycles.
            $this->warn("Retention: {$orphans} orphan workspace(s) skipped (no resolvable workspace owner).");
        }

        if ($failed > 0) {
            $this->warn("Retention: {$failed} workspace(s) failed and were skipped.");
        }

        return true;
    }

    protected function sweepOrphanedFiles(bool $dryRun): void
    {
        $disk = MessageStorage::disk();

        $knownRawPaths = Message::pluck('raw_path')->all();
        $knownAttachmentPaths = Attachment::pluck('path')->all();
        $known = array_flip(array_merge($knownRawPaths, $knownAttachmentPaths));

        $orphaned = 0;

        foreach ($disk->allFiles('messages') as $path) {
            if (isset($known[$path])) {
                continue;
            }

            $orphaned++;

            if (! $dryRun) {
                $disk->delete($path);
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Orphan sweep: {$orphaned} file(s) with no matching DB row.");
    }
}
