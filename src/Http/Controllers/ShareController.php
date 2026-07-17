<?php

namespace Sendtrap\Core\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Sendtrap\Core\Models\MessageShare;
use Symfony\Component\HttpFoundation\Response;

class ShareController extends Controller
{
    /**
     * Public, read-only view of a shared message (no auth).
     */
    public function show(string $token): InertiaResponse
    {
        $share = $this->resolve($token);
        $message = $share->message;

        return Inertia::render('Share/Show', [
            'token' => $share->token,
            'message' => [
                'subject' => $message->subject,
                'from_address' => $message->from_address,
                'from_name' => $message->from_name,
                'to' => $message->to,
                'received_at' => $message->received_at?->toIso8601String(),
                'has_html' => $message->has_html,
                'text' => $message->textBody(),
                'html_url' => route('share.html', $share->token),
            ],
        ]);
    }

    /**
     * Rendered HTML for the public iframe.
     */
    public function html(string $token): Response
    {
        $message = $this->resolve($token)->message;

        return response($message->previewHtml())
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Security-Policy', "script-src 'none'");
    }

    protected function resolve(string $token): MessageShare
    {
        $share = MessageShare::where('token', $token)
            ->with('message.attachments')
            ->firstOrFail();

        abort_if($share->isExpired(), 410, 'This share link has expired.');

        return $share;
    }
}
