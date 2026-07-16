<?php

namespace Sendtrap\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Message;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        $filename = fake()->word().'.txt';

        return [
            'message_id' => Message::factory(),
            'filename' => $filename,
            'content_type' => 'text/plain',
            'size' => fake()->numberBetween(10, 5000),
            'path' => 'messages/attachments/'.fake()->uuid().'/'.Str::uuid()->toString().'-'.$filename,
            'content_id' => null,
            'checksum' => hash('sha256', fake()->uuid()),
            'is_inline' => false,
        ];
    }
}
