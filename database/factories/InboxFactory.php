<?php

namespace Sendtrap\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Project;

/**
 * @extends Factory<Inbox>
 */
class InboxFactory extends Factory
{
    protected $model = Inbox::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => ucfirst(fake()->words(2, true)),
            // smtp_username / smtp_password / api_token auto-generated in the model
            'max_messages' => 500,
        ];
    }
}
