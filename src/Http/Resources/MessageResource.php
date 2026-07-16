<?php

namespace Sendtrap\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight message representation for inbox lists.
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_id' => $this->test_id,
            'from_address' => $this->from_address,
            'from_name' => $this->from_name,
            'to' => $this->to,
            'envelope_to' => $this->envelope_to,
            'subject' => $this->subject,
            'size' => $this->size,
            'is_read' => $this->is_read,
            'has_attachments' => $this->has_attachments,
            'has_unresolved_merge_tags' => $this->has_unresolved_merge_tags,
            'received_at' => $this->received_at?->toIso8601String(),
        ];
    }
}
