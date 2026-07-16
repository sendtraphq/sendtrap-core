<?php

namespace Sendtrap\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Sendtrap\Core\Database\Factories\MessageShareFactory;

class MessageShare extends Model
{
    /** @use HasFactory<MessageShareFactory> */
    use HasFactory;

    protected static function newFactory(): MessageShareFactory
    {
        return MessageShareFactory::new();
    }

    protected $fillable = [
        'message_id',
        'token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MessageShare $share) {
            $share->token ??= Str::random(48);
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
