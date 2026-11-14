<?php

namespace Database\Seeders;

use App\Actions\ProvisionWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development data: two confirmed users sharing one workspace.
 * Both sign in with the password "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(ProvisionWorkspace $provision): void
    {
        $owner = User::factory()->create(['name' => 'Demo Owner', 'email' => 'owner@example.com']);
        $member = User::factory()->create(['name' => 'Demo Member', 'email' => 'member@example.com']);

        $workspace = $provision->handle($owner, 'Demo Workspace');
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    }
}
