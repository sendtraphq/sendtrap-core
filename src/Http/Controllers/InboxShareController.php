<?php

namespace Sendtrap\Core\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Sendtrap\Core\Http\Resources\MessageResource;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\InboxShare;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\MessageStorage;
use Symfony\Component\HttpFoundation\Response;

class InboxShareController extends Controller
{
    /**
     * Public, read-only view of a shared inbox (no auth).
     */
    public function show(string $token): InertiaResponse
    {
        $share = $this->resolve($token);
        $inbox = $share->inbox;

        $messages = $inbox->messages()->orderByDesc('received_at')->paginate(50);

        return Inertia::render('Share/InboxShow', [
            'token' => $share->token,
            'inbox' => [
                'id' => $inbox->id,
                'name' => $inbox->name,
            ],
            'messages' => MessageResource::collection($messages),
            'expires_at' => $share->expires_at->toIso8601String(),
        ]);
    }

    /**
     * JSON list of the shared inbox's messages (supports search + pagination).
     */
    public function messages(Request $request, string $token)
    {
        $inbox = $this->resolve($token)->inbox;

        $search = $request->string('search')->trim();

        $messages = $inbox->messages()
            ->when($search->isNotEmpty(), function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('subject', 'like', $term)
                        ->orWhere('from_address', 'like', $term)
                        ->orWhere('from_name', 'like', $term);
                });
            })
            ->orderByDesc('received_at')
            ->paginate(50);

        return MessageResource::collection($messages);
    }

    /**
     * JSON detail for the reader pane. Read-only — does not mark the
     * message read, since the viewer isn't a team member.
     */
    public function message(string $token, Message $message)
    {
        $inbox = $this->resolve($token)->inbox;
        abort_unless($message->inbox_id === $inbox->id, 404);

        $message->load('attachments');

        return response()->json([
            'data' => [
                'id' => $message->id,
                'from_address' => $message->from_address,
                'from_name' => $message->from_name,
                'to' => $message->to,
                'cc' => $message->cc,
                'subject' => $message->subject,
                'size' => $message->size,
                'has_html' => $message->has_html,
                'has_text' => $message->has_text,
                'has_attachments' => $message->has_attachments,
                'received_at' => $message->received_at?->toIso8601String(),
                'text' => $message->textBody(),
                'attachments' => $message->attachments->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'filename' => $a->filename,
                    'content_type' => $a->content_type,
                    'size' => $a->size,
                    'url' => route('share.inbox.attachment', [$token, $message, $a]),
                ]),
                'urls' => [
                    'html' => route('share.inbox.html', [$token, $message]),
                ],
            ],
        ]);
    }

    /**
     * Rendered HTML for the sandboxed iframe (inline cid: rewritten to
     * share-scoped attachment URLs, not the authenticated ones).
     */
    public function html(string $token, Message $message): Response
    {
        $inbox = $this->resolve($token)->inbox;
        abort_unless($message->inbox_id === $inbox->id, 404);

        $html = $message->renderedHtml(
            fn (Attachment $attachment) => route('share.inbox.attachment', [$token, $message, $attachment])
        );

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Security-Policy', "script-src 'none'");
    }

    public function attachment(string $token, Message $message, Attachment $attachment): Response
    {
        $inbox = $this->resolve($token)->inbox;
        abort_unless($message->inbox_id === $inbox->id, 404);
        abort_if($attachment->message_id !== $message->id, 404);

        return MessageStorage::disk()->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->content_type ?? 'application/octet-stream'],
        );
    }

    protected function resolve(string $token): InboxShare
    {
        $share = InboxShare::where('token', $token)->with('inbox')->firstOrFail();

        abort_if($share->isExpired(), 410, 'This share link has expired.');

        return $share;
    }
}
