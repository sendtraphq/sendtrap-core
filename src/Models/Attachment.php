<?php

namespace Sendtrap\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sendtrap\Core\Database\Factories\AttachmentFactory;

class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected static function newFactory(): AttachmentFactory
    {
        return AttachmentFactory::new();
    }

    protected $fillable = [
        'message_id',
        'filename',
        'content_type',
        'size',
        'path',
        'content_id',
        'checksum',
        'is_inline',
    ];

    protected function casts(): array
    {
        return [
            'is_inline' => 'boolean',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
