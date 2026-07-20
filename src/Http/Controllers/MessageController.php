<?php

namespace Sendtrap\Core\Http\Controllers;

use Illuminate\Http\Request;
use Sendtrap\Core\Http\Resources\MessageDetailResource;
use Sendtrap\Core\Http\Resources\MessageResource;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Storage\MessageDeleter;
use Sendtrap\Core\Support\MessageStorage;
use Sendtrap\Core\Support\SpamCheck;
use Symfony\Component\HttpFoundation\Response;

class MessageController extends Controller
{
    /**
     * JSON list of an inbox's messages (supports search + pagination).
     */
    public function index(Request $request, Inbox $inbox)
    {
        $this->authorize('view', $inbox);

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
     * JSON detail for the reader pane. Marks the message read.
     */
    public function show(Message $message)
    {
        $this->authorize('view', $message);

        if (! $message->is_read) {
            $message->update(['is_read' => true]);
        }

        $message->load('attachments');

        return new MessageDetailResource($message);
    }

    /**
     * Spam Analysis — run the raw message through Postmark's SpamCheck
     * (SpamAssassin) on demand and cache the result on the message.
     */
    public function spam(Message $message)
    {
        $this->authorize('view', $message);

        if ($message->spam_checked_at === null) {
            $result = SpamCheck::check($message->raw());

            if ($result === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Spam analysis is unavailable right now. Please try again shortly.',
                ], 503);
            }

            $message->update([
                'spam_score' => $result['score'],
                'spam_report' => $result['report'] ?: null,
                'spam_checked_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'score' => $message->spam_score,
            'threshold' => SpamCheck::threshold(),
            'is_spam' => $message->isSpam(),
            'report' => $message->spam_report,
            'checked_at' => $message->spam_checked_at?->toIso8601String(),
        ]);
    }

    /**
     * HTML Check — analyse the message's HTML/CSS against caniemail's
     * client feature-support data, on demand, and cache the result. Free on
     * all plans (unlike the API surface for this feature, which is gated).
     */
    public function htmlCheck(Message $message)
    {
        $this->authorize('view', $message);

        $htmlCheck = $message->resolveHtmlCheck();

        return response()->json([
            'status' => 'ok',
            'compatibility_ratio' => $htmlCheck->compatibility_ratio,
            'issues' => $htmlCheck->report,
            'checked_at' => $htmlCheck->checked_at->toIso8601String(),
        ]);
    }

    /**
     * Rendered HTML for the sandboxed iframe (inline cid: rewritten).
     */
    public function html(Message $message): Response
    {
        $this->authorize('view', $message);

        return response($message->previewHtml())
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Security-Policy', "script-src 'none'");
    }

    /**
     * Raw RFC822 source.
     */
    public function raw(Message $message): Response
    {
        $this->authorize('view', $message);

        return response($message->raw())
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function attachment(Message $message, Attachment $attachment): Response
    {
        $this->authorize('view', $message);

        abort_if($attachment->message_id !== $message->id, 404);

        return MessageStorage::disk()->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->content_type ?? 'application/octet-stream'],
        );
    }

    public function markRead(Request $request, Message $message)
    {
        $this->authorize('view', $message);

        $message->update(['is_read' => $request->boolean('is_read', true)]);

        return back();
    }

    public function destroy(Message $message)
    {
        $this->authorize('delete', $message);

        app(MessageDeleter::class)->delete($message);

        return back();
    }

    /**
     * Create (or reuse) a public share link for a message.
     */
    public function share(Message $message)
    {
        $this->authorize('view', $message);

        $share = $message->shares()->firstOrCreate([]);

        return back()->with('share_url', route('share.show', $share->token));
    }
}
