<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Requests look like they come from the web app, so they get a cookie session.
        $this->withHeader('Origin', 'http://localhost:5173');
    })
    ->in('Feature');

/**
 * A workspace with one member in the given role.
 *
 * @return array{0: Workspace, 1: User}
 */
function workspaceWith(WorkspaceRole $role = WorkspaceRole::Owner, ?User $user = null): array
{
    $user ??= User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();

    return [$workspace, $user];
}

function addMember(Workspace $workspace, WorkspaceRole $role = WorkspaceRole::Member): User
{
    $user = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

    return $user;
}

/** Switches the test to a person using the web app, after it acted as an agent. */
function asUser(User $user): mixed
{
    test()->flushSession();

    return test()->withoutToken()->withHeader('Origin', 'http://localhost:5173')->actingAs($user);
}
