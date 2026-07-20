<?php

namespace Sendtrap\Core\Storage;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Log;
use Sendtrap\Core\Contracts\StorageQuota;
use Sendtrap\Core\Models\Message;
use Throwable;

/**
 * The single storage-accounting-aware deletion path for messages (Plan 01a
 * §5): every surface that deletes Message rows — web/API controllers, the
 * Mailtrap-compat API, project cascade, scheduled pruning — routes through
 * delete() so the workspace's storage counter is reduced by the exact
 * removed bytes. The architecture test
 * (MessageDeletionEntryPointsTest) enforces that no other code deletes
 * messages directly.
 *
 * Accounting follows the StorageQuota removal lifecycle: a removal
 * operation is registered before the database is mutated and committed with
 * the bytes of the messages that actually deleted; a batch that fails
 * midway commits only the completed portion, and an operation abandoned
 * entirely expires into reconciliation without reducing the counter.
 *
 * Accounting failures (quota backend down, orphan workspace) never block
 * the deletion itself: admission is also unavailable in that state, and the
 * counter re-converges at the next reconciliation. The one in-ingestion
 * exception: ProcessIncomingMessage's retention trim bypasses this class
 * and folds its removed bytes into the ingestion commit instead, saving two
 * Redis round trips per accepted message.
 */
class MessageDeleter
{
    public function __construct(protected StorageQuota $quota) {}

    /**
     * Delete the given message(s) with exact storage accounting. Returns
     * the number of messages deleted.
     *
     * @param  Message|Enumerable<int, Message>  $messages
     */
    public function delete(Message|Enumerable $messages): int
    {
        $messages = $messages instanceof Message
            ? EloquentCollection::make([$messages])
            : EloquentCollection::make($messages->values()->all());

        if ($messages->isEmpty()) {
            return 0;
        }

        $messages->loadMissing(['inbox.project.workspace', 'attachments']);

        $deleted = 0;

        foreach ($messages->groupBy(fn (Message $message) => $message->inbox?->project?->workspace_id ?? '') as $group) {
            $deleted += $this->deleteGroup($group);
        }

        return $deleted;
    }

    /**
     * Delete one workspace's slice of the batch under a single removal
     * operation.
     *
     * @param  Collection<int, Message>  $group
     */
    protected function deleteGroup(Collection $group): int
    {
        $workspace = $group->first()->inbox?->project?->workspace;
        $bytes = $group->sum(fn (Message $message) => $this->bytesFor($message));

        $operation = null;

        if ($workspace) {
            try {
                $operation = $this->quota->beginRemoval($workspace, $bytes);
            } catch (Throwable $e) {
                Log::warning('storage_quota.removal_begin_failed', [
                    'workspace_id' => $workspace->id(),
                    'bytes' => $bytes,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $deleted = 0;
        $removedBytes = 0;

        try {
            foreach ($group as $message) {
                $messageBytes = $this->bytesFor($message);
                $message->delete();
                $removedBytes += $messageBytes;
                $deleted++;
            }
        } finally {
            if ($operation?->accountable()) {
                try {
                    // Commit only what actually deleted — a batch that
                    // failed midway reduces the counter by the completed
                    // portion; the remainder stays counted until
                    // reconciliation.
                    $this->quota->commit($operation, 0, $removedBytes);
                } catch (Throwable $e) {
                    Log::warning('storage_quota.removal_commit_failed', [
                        'workspace_id' => $workspace?->id(),
                        'bytes' => $removedBytes,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $deleted;
    }

    protected function bytesFor(Message $message): int
    {
        return (int) $message->size + (int) $message->attachments->sum('size');
    }
}
