<?php

namespace Sendtrap\Core\Http\Resources\Mailtrap;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mailtrap-compatible sandbox representation, returned by the /clean and
 * /all_read alias endpoints. Only fields with a real Sendtrap equivalent are
 * included — POP3, templates and granular permissions don't exist here.
 */
class SandboxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->smtp_username,
            'email_username' => $this->smtp_username,
            'domain' => config('sendtrap.public_host'),
            'email_domain' => config('sendtrap.public_host'),
            'smtp_ports' => config('sendtrap.public_ports'),
            'emails_count' => $this->messages_count,
            'emails_unread_count' => $this->unread_count,
            'used' => ($this->messages_count ?? 0) > 0,
        ];
    }
}
