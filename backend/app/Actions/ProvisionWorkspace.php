<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Creates a workspace owned by the given user, with a sample project so the
 * first screen is never empty.
 */
class ProvisionWorkspace
{
    public function handle(User $owner, string $name, ?string $slug = null): Workspace
    {
        return DB::transaction(function () use ($owner, $name, $slug): Workspace {
            $workspace = Workspace::create([
                'name' => $name,
                'slug' => $slug ?? Workspace::uniqueSlugFor($name),
                'created_by' => $owner->id,
            ]);

            $workspace->memberships()->create([
                'user_id' => $owner->id,
                'role' => WorkspaceRole::Owner,
            ]);

            Project::create([
                'workspace_id' => $workspace->id,
                'key' => 'DEMO',
                'name' => 'Getting started',
                'description' => 'A sample project to try things out. Rename it or delete it when you are ready.',
                'created_by' => $owner->id,
            ]);

            return $workspace;
        });
    }
}
