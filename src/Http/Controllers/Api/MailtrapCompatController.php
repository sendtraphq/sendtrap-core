<?php

namespace Sendtrap\Core\Http\Controllers\Api;

use Illuminate\Http\Request;
use Sendtrap\Core\Http\Controllers\Api\Concerns\ScopedToInboxToken;
use Sendtrap\Core\Http\Controllers\Controller;
use Sendtrap\Core\Http\Resources\Mailtrap\AttachmentResource;
use Sendtrap\Core\Http\Resources\Mailtrap\SandboxMessageDetailResource;
use Sendtrap\Core\Http\Resources\Mailtrap\SandboxMessageResource;
use Sendtrap\Core\Http\Resources\Mailtrap\SandboxResource;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\MessageStorage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mailtrap-compatible alias routes under /api/sandboxes/{sandbox}/... — same
 * token auth and inbox scoping as the native /api/v1 routes. {sandbox} is
 * accepted but not validated (the bearer token already fully scopes the
 * request), so an existing Mailtrap test helper works after only a base URL
 * + token swap. Fields with no Sendtrap equivalent (templates, blacklist
 * reports, POP3, granular permissions) are omitted rather than faked — see
 * the "Mailtrap compatibility" section of /docs/api.
 *
 * Every action below declares an unused $sandbox parameter even though its
 * value is ignored: Laravel fills non-bound route parameters positionally,
 * so omitting it would misalign {message}/{attachment} into the wrong
 * argument (see route param ordering — a real Laravel footgun when a route
 * has more URI parameters than the method declares).
 */
class MailtrapCompatController extends Controller
{
    use ScopedToInboxToken;

    public function index(Request $request, string $sandbox)
    {
        $inbox = $this->inbox($request);
        $search = $request->string('search')->trim();

        $query = $inbox->messages()
            ->when($search->isNotEmpty(), function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(fn ($inner) => $inner->where('subject', 'like', $term)
                    ->orWhere('to', 'like', $term)
                    ->orWhere('from_name', 'like', $term));
            })
            ->orderByDesc('id');

        if ($lastId = $request->integer('last_id')) {
            $query->where('id', '<', $lastId);
        } else {
            $query->forPage(max(1, $request->integer('page', 1)), 30);
        }

        $messages = $query->limit(30)->get();
        $sandboxSlug = $inbox->smtp_username;

        return response()->json(
            $messages->map(fn ($m) => (new SandboxMessageResource($m, $sandboxSlug))->resolve())->all()
        );
    }

    public function show(Request $request, string $sandbox, Message $message)
    {
        $this->ensureOwned($request, $message);

        return response()->json(
            (new SandboxMessageDetailResource($message, $this->inbox($request)->smtp_username))->resolve()
        );
    }

    public function update(Request $request, string $sandbox, Message $message)
    {
        $this->ensureOwned($request, $message);

        $isRead = $request->exists('message.is_read')
            ? $request->input('message.is_read')
            : $request->input('is_read', true);

        $message->update(['is_read' => filter_var($isRead, FILTER_VALIDATE_BOOLEAN)]);

        return response()->json(
            (new SandboxMessageDetailResource($message, $this->inbox($request)->smtp_username))->resolve()
        );
    }

    public function destroy(Request $request, string $sandbox, Message $message)
    {
        $this->ensureOwned($request, $message);

        $sandboxSlug = $this->inbox($request)->smtp_username;
        $message->delete();

        return response()->json(
            (new SandboxMessageDetailResource($message, $sandboxSlug))->resolve()
        );
    }

    public function bodyTxt(Request $request, string $sandbox, Message $message): Response
    {
        $this->ensureOwned($request, $message);

        return response($message->textBody() ?? '')->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function bodyHtml(Request $request, string $sandbox, Message $message): Response
    {
        $this->ensureOwned($request, $message);

        return response($message->renderedHtml())->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function bodyHtmlSource(Request $request, string $sandbox, Message $message): Response
    {
        $this->ensureOwned($request, $message);

        return response($message->htmlBody() ?? '')->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function bodyRaw(Request $request, string $sandbox, Message $message): Response
    {
        $this->ensureOwned($request, $message);

        return response($message->raw())->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function bodyEml(Request $request, string $sandbox, Message $message): Response
    {
        $this->ensureOwned($request, $message);

        return response($message->raw())
            ->header('Content-Type', 'message/rfc822')
            ->header('Content-Disposition', 'attachment; filename="message-'.$message->id.'.eml"');
    }

    public function mailHeaders(Request $request, string $sandbox, Message $message)
    {
        $this->ensureOwned($request, $message);

        $headers = [];
        foreach ($message->headerLines() as $header) {
            $headers[$header['name']] = $header['value'];
        }

        return response()->json(['headers' => $headers]);
    }

    public function attachments(Request $request, string $sandbox, Message $message)
    {
        $this->ensureOwned($request, $message);
        $sandboxSlug = $this->inbox($request)->smtp_username;

        return response()->json(
            $message->attachments->map(fn ($a) => (new AttachmentResource($a, $sandboxSlug))->resolve())->all()
        );
    }

    public function attachment(Request $request, string $sandbox, Message $message, Attachment $attachment)
    {
        $this->ensureOwned($request, $message);
        abort_if($attachment->message_id !== $message->id, 404);

        return response()->json(
            (new AttachmentResource($attachment, $this->inbox($request)->smtp_username))->resolve()
        );
    }

    public function attachmentDownload(Request $request, string $sandbox, Message $message, Attachment $attachment): Response
    {
        $this->ensureOwned($request, $message);
        abort_if($attachment->message_id !== $message->id, 404);

        return MessageStorage::disk()->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->content_type ?? 'application/octet-stream'],
        );
    }

    public function clean(Request $request, string $sandbox)
    {
        $inbox = $this->inbox($request);
        $inbox->messages()->get()->each->delete();

        return response()->json((new SandboxResource($this->withCounts($inbox)))->resolve());
    }

    public function allRead(Request $request, string $sandbox)
    {
        $inbox = $this->inbox($request);
        $inbox->messages()->where('is_read', false)->update(['is_read' => true]);

        return response()->json((new SandboxResource($this->withCounts($inbox)))->resolve());
    }

    private function withCounts($inbox)
    {
        return $inbox->loadCount([
            'messages',
            'messages as unread_count' => fn ($q) => $q->where('is_read', false),
        ]);
    }
}
