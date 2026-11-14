<?php

namespace Database\Factories;

use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Use App\Services\WorkItemWriter in tests that care about keys or history;
 * this factory is for bulk rows and needs project_id, state_id and sequence.
 *
 * @extends Factory<WorkItem>
 */
class WorkItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'priority' => 0,
            'kind' => 'task',
        ];
    }
}
