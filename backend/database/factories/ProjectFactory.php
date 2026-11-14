<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'key' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(2, true),
        ];
    }
}
