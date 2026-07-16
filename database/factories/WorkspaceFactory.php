<?php

namespace Sendtrap\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sendtrap\Core\Models\Workspace;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'allowed_ips' => null,
        ];
    }
}
