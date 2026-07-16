<?php

namespace Sendtrap\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Support\MessageLinter;

/**
 * Full message representation for the reader pane: parsed bodies, headers,
 * attachments and tech info.
 */
class MessageDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Served by both the session-authed web reader and the token-authed
        // API — point generated URLs at whichever route set the caller can
        // actually reach.
        $isApi = $request->is('api/*');

        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'message_id' => $this->message_id,
            'test_id' => $this->test_id,
            'envelope_from' => $this->envelope_from,
            'envelope_to' => $this->envelope_to,
            'from_address' => $this->from_address,
            'from_name' => $this->from_name,
            'to' => $this->to,
            'cc' => $this->cc,
            'subject' => $this->subject,
            'size' => $this->size,
            'is_read' => $this->is_read,
            'has_html' => $this->has_html,
            'has_text' => $this->has_text,
            'has_attachments' => $this->has_attachments,
            'has_unresolved_merge_tags' => $this->has_unresolved_merge_tags,
            'unresolved_merge_tags' => $this->unresolved_merge_tags,
            'received_at' => $this->received_at?->toIso8601String(),
            'html' => $this->has_html ? $this->htmlBody() : null,
            'text' => $this->textBody(),
            'links' => $this->links(),
            'checks' => $this->checks($isApi),
            'headers' => $this->headerLines(),
            'attachments' => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'content_type' => $a->content_type,
                'size' => $a->size,
                'checksum' => $a->checksum,
                'is_inline' => $a->is_inline,
                'url' => $isApi
                    ? route('api.messages.attachment', [$this->resource, $a])
                    : route('messages.attachment', [$this->resource, $a]),
            ]),
            'urls' => [
                'raw' => $isApi ? route('api.messages.raw', $this->resource) : route('messages.raw', $this->resource),
                'html' => $isApi ? route('api.messages.html', $this->resource) : route('messages.html', $this->resource),
            ],
        ];
    }

    /**
     * Message-quality checks plus, for API callers on Starter+, a summary
     * html_compatibility entry from any already-cached HTML Check result.
     * Never forces a compute here — show() stays cheap, matching this
     * method's existing philosophy for the rest of MessageLinter's checks.
     */
    protected function checks(bool $isApi): array
    {
        $checks = MessageLinter::lint($this->resource);

        $htmlCheck = $this->resource->htmlCheck;

        // Plan 06 Phase 2 (§5.1 item 9): the API-caller plan gate resolves
        // through Entitlements against the inbox's workspace. A null
        // workspace (not yet backfilled) reads as no-API-access — the
        // gated entry is omitted, never a null flowing into the
        // non-nullable contract parameter (§5.0.1).
        $workspace = $this->resource->inbox->workspace;

        if ($htmlCheck !== null && (! $isApi || ($workspace !== null && app(Entitlements::class)->for($workspace)->hasHtmlCheckApi()))) {
            $hasIssues = $htmlCheck->report !== [];

            $checks[] = [
                'key' => 'html_compatibility',
                'passed' => ! $hasIssues,
                'severity' => ! $hasIssues
                    ? 'info'
                    : (collect($htmlCheck->report)->contains(fn ($issue) => $issue['severity'] === 'error') ? 'error' : 'warn'),
            ];
        }

        return $checks;
    }
}
