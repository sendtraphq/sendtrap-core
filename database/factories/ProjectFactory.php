<?php

namespace Sendtrap\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Models\Workspace;

/**
 * @extends Factory<Project>
 *
 * M-2 (Plan 06 Phase 3b §1.2): keyed on workspace_id only — core's Project
 * has no team_id concept. Setting workspace_id here is a DELIBERATE,
 * DOCUMENTED exemption from N-6 ("workspace_id is never mass-assignable"):
 * Eloquent factories create models via Model::unguarded() mass assignment,
 * so factory definitions are not constrained by $fillable — this is the one
 * sanctioned exception, for factories only. N-6 still fully governs
 * production code (controllers, listeners, any create()/update() against
 * user input).
 *
 * Cloud tests needing a team_id-carrying Project decorate rather than
 * replace: Project::factory()->state(['team_id' => $team->id,
 * 'workspace_id' => null])->create() — the Cloud host's Project::creating
 * listener derives workspace_id from team_id exactly as the old model hook
 * did (H-2).
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'workspace_id' => Workspace::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        ];
    }
}
