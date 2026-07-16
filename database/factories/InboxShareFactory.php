<?php

namespace Sendtrap\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\InboxShare;

/**
 * @extends Factory<InboxShare>
 */
class InboxShareFactory extends Factory
{
    protected $model = InboxShare::class;

    public function definition(): array
    {
        return [
            'inbox_id' => Inbox::factory(),
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
        ];
    }
}
