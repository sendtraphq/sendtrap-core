<?php

namespace Sendtrap\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sendtrap\Core\Contracts\LegacyOwnershipFallback;
use Sendtrap\Core\Contracts\StorageQuota;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Events\MessageReceived;
use Sendtrap\Core\Exceptions\UnresolvedWorkspaceOwnerException;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Storage\StorageReservation;
use Sendtrap\Core\Support\MergeTagDetector;
use Sendtrap\Core\Support\MessageStorage;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Message as MimeMessage;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $inboxId,
        public string $raw,
        public ?string $envelopeFrom = null,
        public array $envelopeTo = [],
    ) {}

    public function handle(): void
    {
        // Plan 06 Phase 2 (§5.1 item 6, D-18): the job carries only inboxId
        // and re-derives ownership on handle(); the workspace is eager-loaded
        // so the flip adds no lazy N+1 versus the pre-flip lazy access.
        // Phase 3b slice 4: the eager-load chain now stops at
        // project.workspace — the host-specific owner hop behind it is no
        // longer named here; a host adapter resolves it (once per model
        // instance) behind the UsageMeter/LegacyOwnershipFallback contracts.
        $inbox = Inbox::with('project.workspace')->find($this->inboxId);

        if (! $inbox) {
            return; // inbox was deleted between receipt and processing
        }

        $parsed = MimeMessage::from($this->raw, true);
        $incomingBytes = $this->storedSize($parsed);

        // Storage quota via StorageQuota with the real Workspace (Plan 01a:
        // an atomic reserve, no longer UsageMeter::wouldExceedStorage()'s
        // read-modify-write). The fallback
        // trigger is DERIVED, never configured (§3.3/§3.4 gate × flag
        // matrix): a null workspace (project not yet backfilled), or a
        // workspace whose concrete owner the host adapter cannot resolve
        // (UnresolvedWorkspaceOwnerException — the second trigger, §3.2),
        // decides the LegacyOwnershipFallback is needed. active() is
        // consulted only AFTER that derivation, to decide proceed (true, the
        // default — today's behavior) versus fail loud (false — the job
        // throws and lands in failed_jobs: visible, alertable, retryable;
        // the check is never silently skipped, §5.0.1 row 5 / N-1).
        // A quota-backend failure (e.g. Redis down) deliberately propagates:
        // the job fails retryably and stays visible to queue operations —
        // never a silent "allowed" or "over quota".
        $workspace = $inbox->project?->workspace;
        $fallback = app(LegacyOwnershipFallback::class);
        $quota = app(StorageQuota::class);
        $usesFallback = false;
        $reservation = null;

        if ($workspace) {
            try {
                $reservation = $quota->reserve($workspace, $incomingBytes);
            } catch (UnresolvedWorkspaceOwnerException) {
                $workspace = null; // resolved but orphaned: fall through to the fallback path below
            }
        }

        if ($workspace) {
            if ($reservation->shouldRetry()) {
                // Admission is paused behind a reconciliation barrier (or
                // first-touch initialization). Requeue rather than drop —
                // the pause is one aggregate-query round; queueing absorbs
                // it. A fresh dispatch (not release()) so a worker
                // configured with --tries=1 doesn't fail the job for being
                // released once.
                static::dispatch($this->inboxId, $this->raw, $this->envelopeFrom, $this->envelopeTo)
                    ->delay(now()->addSeconds(2));

                return;
            }

            if ($reservation->isBlocked()) {
                return; // workspace is over (or this message would push it over) its plan's storage quota; drop the message
            }
        }

        if (! $workspace) {
            if (! $fallback->active()) {
                // Kill switch engaged: an operator has asserted no record
                // should still need the fallback — fail the job loudly
                // instead of running (or skipping) the legacy-owner checks.
                throw new UnresolvedWorkspaceOwnerException(null, sprintf(
                    'Inbox [%d] resolves no workspace owner and the legacy ownership fallback is disabled.',
                    $inbox->id,
                ));
            }

            $usesFallback = true;

            // H-N1: an inbox whose legacy owner is also unresolvable gets
            // the contract's constant "no limit" answer here — accepted
            // unchecked, exactly like the pre-move double-null fall-through.
            if ($fallback->wouldExceedStorage($inbox, $incomingBytes)) {
                return; // legacy owner is over (or this message would push it over) its plan's storage quota; drop the message
            }
        }

        // Plan 01a explicit lifecycle: everything between here and the
        // commit runs under the reservation — any ordinary early return or
        // exception releases it in the finally, so a failed attempt never
        // stays charged. Post-persistence side effects (broadcast, forward,
        // webhook) stay OUTSIDE the guarded section: their failures must not
        // release bytes belonging to an already-stored message.
        $committed = false;

        try {
            // Persist the raw RFC822 source to disk.
            $rawPath = 'messages/'.Str::uuid()->toString().'.eml';

            if (! MessageStorage::disk()->put($rawPath, $this->raw)) {
                Log::error('message.store_failed: could not write raw message to storage', [
                    'inbox_id' => $inbox->id,
                    'path' => $rawPath,
                ]);

                return;
            }

            $from = $this->firstAddress($parsed->getHeader('From'));
            $html = $parsed->getHtmlContent();
            $text = $parsed->getTextContent();
            $mergeTags = MergeTagDetector::detect($html, $text);

            $message = $inbox->messages()->create([
                'message_id' => trim((string) $parsed->getHeaderValue('Message-ID'), '<> ') ?: null,
                'test_id' => trim((string) $parsed->getHeaderValue('X-Sendtrap-Test-Id')) ?: null,
                'envelope_from' => $this->envelopeFrom,
                'envelope_to' => $this->envelopeTo,
                'from_address' => $from['address'] ?? null,
                'from_name' => $from['name'] ?? null,
                'to' => $this->addressList($parsed->getHeader('To')),
                'cc' => $this->addressList($parsed->getHeader('Cc')),
                'subject' => $parsed->getHeaderValue('Subject'),
                'size' => strlen($this->raw),
                'has_html' => $html !== null && $html !== '',
                'has_text' => $text !== null && $text !== '',
                'has_attachments' => false,
                'has_unresolved_merge_tags' => $mergeTags['has_unresolved_merge_tags'],
                'unresolved_merge_tags' => $mergeTags['unresolved_merge_tags'],
                'raw_path' => $rawPath,
                'received_at' => now(),
            ]);

            $storedAttachmentBytes = $this->storeAttachments($parsed, $message);

            if ($message->attachments()->exists()) {
                $message->update(['has_attachments' => true]);
            }

            // Retention runs inside the reservation window so its exact
            // removed bytes fold into the same commit — one reserve/commit
            // script pair per accepted message, no extra round trips.
            $removedBytes = $this->enforceRetention($inbox);

            // Finalize with the bytes actually persisted: the raw source
            // plus only the attachments that stored successfully — a failed
            // attachment write must not leave its prospective size charged.
            $this->commitReservation(
                $quota,
                $reservation,
                strlen($this->raw) + $storedAttachmentBytes,
                $removedBytes,
            );

            $committed = true;
        } finally {
            if (! $committed && $reservation?->accountable()) {
                try {
                    $quota->release($reservation);
                } catch (Throwable $e) {
                    // Release itself failed (quota backend down mid-crash):
                    // the operation token expires into reconciliation, which
                    // reinstalls the database truth before admission reopens.
                    Log::error('storage_quota.release_failed: reservation left to expire into reconciliation', [
                        'inbox_id' => $inbox->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        MessageReceived::dispatch($message);

        // Auto-forward gate via UsageMeter (§5.1 item 6); the fallback path
        // reuses the derivation above — one trigger decision per job, and
        // the same contract instance, so the fallback's per-inbox owner
        // resolution is shared across the storage and forward checks (L-4).
        if ($inbox->auto_forward_to) {
            if ($workspace) {
                if (app(UsageMeter::class)->canForward($workspace)) {
                    ForwardMessage::dispatch($message->id, $inbox->auto_forward_to);
                    app(UsageMeter::class)->recordForward($workspace);
                }
            } elseif ($usesFallback) {
                if ($fallback->canForward($inbox)) {
                    ForwardMessage::dispatch($message->id, $inbox->auto_forward_to);
                    $fallback->recordForward($inbox);
                }
            }
        }

        if ($inbox->webhook_url) {
            DeliverWebhook::dispatch($message->id);
        }
    }

    /**
     * Finalize the reservation with the net stored delta. The message IS
     * persisted by this point, so a quota-backend failure here must not
     * fail the job (a retry would store the message twice) and must not
     * fall through to release() — the untouched operation token expires
     * into reconciliation instead, which bounds the drift.
     */
    protected function commitReservation(StorageQuota $quota, ?StorageReservation $reservation, int $storedBytes, int $removedBytes): void
    {
        if (! $reservation?->accountable()) {
            return;
        }

        try {
            $quota->commit($reservation, $storedBytes, $removedBytes);
        } catch (Throwable $e) {
            Log::error('storage_quota.commit_failed: reservation left to expire into reconciliation', [
                'stored_bytes' => $storedBytes,
                'removed_bytes' => $removedBytes,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist all attachment parts to disk and create Attachment rows.
     * Returns the byte total of the attachments that actually stored — a
     * failed write is skipped (logged) and must not stay charged against
     * the storage reservation.
     */
    protected function storeAttachments(MimeMessage $parsed, Message $message): int
    {
        $bytes = 0;

        foreach ($parsed->getAllAttachmentParts() as $part) {
            $filename = $part->getFilename() ?: 'attachment-'.Str::random(6);
            $contentId = trim((string) $part->getContentId(), '<> ') ?: null;
            $content = $part->getContent();
            $path = 'messages/attachments/'.$message->id.'/'.Str::uuid()->toString().'-'.$filename;

            if (! MessageStorage::disk()->put($path, $content)) {
                Log::error('message.attachment_store_failed: could not write attachment to storage', [
                    'message_id' => $message->id,
                    'path' => $path,
                ]);

                continue;
            }

            $message->attachments()->create([
                'filename' => $filename,
                'content_type' => $part->getContentType(),
                'size' => strlen($content),
                'path' => $path,
                'content_id' => $contentId,
                'checksum' => hash('sha256', $content),
                'is_inline' => $part->getContentDisposition() === 'inline' || $contentId !== null,
            ]);

            $bytes += strlen($content);
        }

        return $bytes;
    }

    /**
     * Bytes this message will occupy: the raw MIME source plus each decoded
     * attachment that is persisted as a separate object.
     */
    protected function storedSize(MimeMessage $parsed): int
    {
        $attachmentBytes = 0;

        foreach ($parsed->getAllAttachmentParts() as $part) {
            $attachmentBytes += strlen($part->getContent());
        }

        return strlen($this->raw) + $attachmentBytes;
    }

    /**
     * Trim the inbox down to its max_messages cap (oldest first). Returns
     * the exact bytes removed (raw sizes + attachment sizes) so the caller
     * can fold them into the ingestion commit — deliberately NOT routed
     * through MessageDeleter, whose own removal operation would cost two
     * extra quota round trips per accepted message.
     */
    protected function enforceRetention(Inbox $inbox): int
    {
        $overflow = $inbox->messages()->count() - $inbox->max_messages;

        if ($overflow <= 0) {
            return 0;
        }

        $bytes = 0;

        $inbox->messages()
            ->with('attachments')
            ->orderBy('received_at')
            ->limit($overflow)
            ->get()
            ->each(function (Message $message) use (&$bytes) {
                $bytes += (int) $message->size + (int) $message->attachments->sum('size');
                $message->delete();
            });

        return $bytes;
    }

    /**
     * @return array{address: ?string, name: ?string}
     */
    protected function firstAddress($header): array
    {
        if ($header instanceof AddressHeader && $header->getAddresses()) {
            $addr = $header->getAddresses()[0];

            return ['address' => $addr->getEmail(), 'name' => $addr->getName() ?: null];
        }

        return ['address' => null, 'name' => null];
    }

    /**
     * @return list<array{address: string, name: ?string}>
     */
    protected function addressList($header): array
    {
        if (! $header instanceof AddressHeader) {
            return [];
        }

        return collect($header->getAddresses())
            ->map(fn ($a) => ['address' => $a->getEmail(), 'name' => $a->getName() ?: null])
            ->all();
    }
}
