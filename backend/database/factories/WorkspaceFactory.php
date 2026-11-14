<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
        ];
    }

    public function withMember(User $user, WorkspaceRole $role = WorkspaceRole::Member): static
    {
        return $this->afterCreating(function (Workspace $workspace) use ($user, $role): void {
            $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        });
    }
}
