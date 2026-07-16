<?php

namespace Sendtrap\Core\Http\Resources\Mailtrap;

use Illuminate\Http\Request;

class SandboxMessageDetailResource extends SandboxMessageResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'html_body_size' => $this->has_html ? strlen((string) $this->htmlBody()) : 0,
            'text_body_size' => strlen((string) $this->textBody()),
        ]);
    }
}
