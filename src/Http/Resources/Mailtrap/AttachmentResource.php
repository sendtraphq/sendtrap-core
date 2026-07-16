<?php

namespace Sendtrap\Core\Http\Resources\Mailtrap;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Number;

class AttachmentResource extends JsonResource
{
    public function __construct($resource, protected string $sandbox)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'filename' => $this->filename,
            'attachment_type' => $this->is_inline ? 'inline' : 'attachment',
            'content_type' => $this->content_type,
            'content_id' => $this->content_id,
            'transfer_encoding' => null,
            'attachment_size' => $this->size,
            'checksum' => $this->checksum,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'attachment_human_size' => Number::fileSize($this->size ?? 0),
            'download_path' => url("/api/sandboxes/{$this->sandbox}/messages/{$this->message_id}/attachments/{$this->id}/download"),
        ];
    }
}
