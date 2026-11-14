<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** An agent in the given workspace, owned by the given person. */
    public function agent(Workspace $workspace, User $owner): static
    {
        return $this->state(fn () => [
            'type' => 'agent',
            'workspace_id' => $workspace->id,
            'owner_id' => $owner->id,
            'email_notifications' => false,
        ])->afterCreating(function (User $agent) use ($workspace): void {
            $workspace->memberships()->create(['user_id' => $agent->id, 'role' => WorkspaceRole::Member]);
        });
    }
}
