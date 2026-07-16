<?php

namespace Sendtrap\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\MessageShare;

/**
 * @extends Factory<MessageShare>
 */
class MessageShareFactory extends Factory
{
    protected $model = MessageShare::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
        ];
    }
}
