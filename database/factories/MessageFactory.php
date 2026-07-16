<?php

namespace Sendtrap\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'inbox_id' => Inbox::factory(),
            'message_id' => fake()->uuid().'@example.com',
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'to' => [['address' => fake()->safeEmail(), 'name' => fake()->name()]],
            'cc' => [],
            'subject' => fake()->sentence(),
            'size' => fake()->numberBetween(500, 50000),
            'is_read' => false,
            'has_html' => true,
            'has_text' => true,
            'has_attachments' => false,
            'raw_path' => 'messages/'.fake()->uuid().'.eml',
            'received_at' => now(),
        ];
    }
}
