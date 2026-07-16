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
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Events\MessageReceived;
use Sendtrap\Core\Exceptions\UnresolvedWorkspaceOwnerException;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\MergeTagDetector;
use Sendtrap\Core\Support\MessageStorage;
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

        // Storage quota via UsageMeter with the real Workspace. The fallback
        // trigger is DERIVED, never configured (§3.3/§3.4 gate × flag
        // matrix): a null workspace (project not yet backfilled), or a
        // workspace whose concrete owner the host adapter cannot resolve
        // (UnresolvedWorkspaceOwnerException — the second trigger, §3.2),
        // decides the LegacyOwnershipFallback is needed. active() is
        // consulted only AFTER that derivation, to decide proceed (true, the
        // default — today's behavior) versus fail loud (false — the job
        // throws and lands in failed_jobs: visible, alertable, retryable;
        // the check is never silently skipped, §5.0.1 row 5 / N-1).
        $workspace = $inbox->project?->workspace;
        $fallback = app(LegacyOwnershipFallback::class);
        $usesFallback = false;

        if ($workspace) {
            try {
                if (app(UsageMeter::class)->wouldExceedStorage($workspace, $incomingBytes)) {
                    return; // workspace is over (or this message would push it over) its plan's storage quota; drop the message
                }
            } catch (UnresolvedWorkspaceOwnerException) {
                $workspace = null; // resolved but orphaned: fall through to the fallback path below
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

        $hasAttachments = $this->storeAttachments($parsed, $message);

        if ($hasAttachments) {
            $message->update(['has_attachments' => true]);
        }

        $this->enforceRetention($inbox);

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
     * Persist all attachment parts to disk and create Attachment rows.
     */
    protected function storeAttachments(MimeMessage $parsed, Message $message): bool
    {
        $stored = false;

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

            $stored = true;
        }

        return $stored;
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
     * Trim the inbox down to its max_messages cap (oldest first).
     */
    protected function enforceRetention(Inbox $inbox): void
    {
        $overflow = $inbox->messages()->count() - $inbox->max_messages;

        if ($overflow <= 0) {
            return;
        }

        $inbox->messages()
            ->orderBy('received_at')
            ->limit($overflow)
            ->get()
            ->each->delete();
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
