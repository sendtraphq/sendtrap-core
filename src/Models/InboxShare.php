<?php

namespace Sendtrap\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Sendtrap\Core\Database\Factories\InboxShareFactory;

class InboxShare extends Model
{
    /** @use HasFactory<InboxShareFactory> */
    use HasFactory;

    protected static function newFactory(): InboxShareFactory
    {
        return InboxShareFactory::new();
    }

    protected $fillable = [
        'inbox_id',
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
        static::creating(function (InboxShare $share) {
            $share->token ??= Str::random(48);
        });
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
