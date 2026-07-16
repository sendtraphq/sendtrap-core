<?php

namespace Sendtrap\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageHtmlCheck extends Model
{
    protected $fillable = [
        'message_id',
        'compatibility_ratio',
        'report',
        'data_version',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'compatibility_ratio' => 'float',
            'report' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
