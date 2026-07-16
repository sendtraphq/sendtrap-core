<?php

namespace Sendtrap\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Sendtrap\Core\Models\Inbox;

class InboxResource extends JsonResource
{
    /**
     * The credential-visibility gate. Integration credentials —
     * smtp_username/smtp_password/api_token, the active share URL, and
     * webhook_url/auto_forward_to — are manager-visible only, everywhere
     * this resource serializes. webhook_url/auto_forward_to are config, not content, but
     * can themselves embed credentials in the URL/address, so they ride the
     * same gate rather than getting a separate carve-out.
     *
     * Two clauses, both load-bearing (see §4.7 for the full rationale):
     * (a) the web/session manager path (Gate::authorize('update', $inbox) ->
     *     InboxPolicy::update -> WorkspaceAccess::canManage); (b) the
     * inbox-token API path, where $request->user() is null but the caller
     * has already proven possession of this exact inbox's own token
     * (AuthenticateInboxToken sets the 'inbox' request attribute) — without
     * clause (b), Api\InboxController::show()'s own inbox-details response
     * would lose its own credentials, breaking the token API in every host,
     * Cloud included.
     */
    protected function canRevealCredentials(Request $request): bool
    {
        if ($request->user()?->can('update', $this->resource)) {
            return true;
        }

        $authed = $request->attributes->get('inbox');

        return $authed instanceof Inbox && $authed->is($this->resource);
    }

    public function toArray(Request $request): array
    {
        $reveal = $this->canRevealCredentials($request);

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'smtp_host' => config('sendtrap.public_host'),
            'smtp_ports' => config('sendtrap.public_ports'),
            'smtp_username' => $this->when($reveal, fn () => $this->smtp_username),
            'smtp_password' => $this->when($reveal, fn () => $this->smtp_password),
            'api_token' => $this->when($reveal, fn () => $this->api_token),
            'max_messages' => $this->max_messages,
            'auto_forward_to' => $this->when($reveal, fn () => $this->auto_forward_to),
            'webhook_url' => $this->when($reveal, fn () => $this->webhook_url),
            'allowed_ips' => $this->allowed_ips ?? [],
            'effective_allowed_ips' => $this->effectiveAllowedIps(),
            'messages_count' => $this->whenCounted('messages'),
            'unread_count' => $this->when(isset($this->unread_count), fn () => (int) $this->unread_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'share' => $this->when($reveal && $this->relationLoaded('shares'), function () {
                $active = $this->activeShare();

                return $active ? [
                    'url' => route('share.inbox.show', $active->token),
                    'expires_at' => $active->expires_at->toIso8601String(),
                ] : null;
            }),
        ];
    }
}
