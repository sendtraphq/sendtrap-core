<?php

namespace Sendtrap\Core\Http\Resources\Mailtrap;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Number;

/**
 * Mailtrap-compatible SandboxMessage representation (list form) for the
 * /api/sandboxes/... alias routes. Fields with no Sendtrap equivalent
 * (template_id, template_variables, blacklists_report_info,
 * smtp_information) are intentionally omitted rather than faked.
 */
class SandboxMessageResource extends JsonResource
{
    public function __construct($resource, protected string $sandbox)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sandbox_id' => $this->inbox_id,
            'subject' => $this->subject,
            'sent_at' => $this->received_at?->toIso8601String(),
            'from_email' => $this->from_address,
            'from_name' => $this->from_name,
            'to_email' => $this->to[0]['address'] ?? null,
            'to_name' => $this->to[0]['name'] ?? null,
            'email_size' => $this->size,
            'human_size' => Number::fileSize($this->size ?? 0),
            'is_read' => $this->is_read,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'html_path' => $this->path('body.html'),
            'txt_path' => $this->path('body.txt'),
            'raw_path' => $this->path('body.raw'),
            'html_source_path' => $this->path('body.htmlsource'),
            'download_path' => $this->path('body.eml'),
        ];
    }

    protected function path(string $suffix): string
    {
        return url("/api/sandboxes/{$this->sandbox}/messages/{$this->id}/{$suffix}");
    }
}
