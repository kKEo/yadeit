<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkItemWriter;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Creates a workspace owned by the given user, with a sample project so the
 * first screen is never empty.
 */
class ProvisionWorkspace
{
    private const SAMPLE_ITEMS = [
        ['title' => 'Welcome! Open me to see what a work item looks like', 'state' => 'In progress', 'priority' => 'medium', 'assignee' => 'me',
            'description' => "A work item has a **state**, a priority, an assignee and a Markdown description.\n\nTry changing the state, then leave a comment below."],
        ['title' => 'Invite a teammate', 'state' => 'Todo', 'description' => 'Open **Members** and send an invitation by email.'],
        ['title' => 'Create your first real project', 'state' => 'Todo', 'description' => 'Each project gets a short key, such as `WEB`. Its items are then numbered `WEB-1`, `WEB-2`, and so on.'],
    ];

    public function __construct(
        private CreateProject $createProject,
        private WorkItemWriter $writer,
        private CurrentWorkspace $current,
    ) {}

    public function handle(User $owner, string $name, ?string $slug = null): Workspace
    {
        return DB::transaction(function () use ($owner, $name, $slug): Workspace {
            $workspace = new Workspace([
                'name' => $name,
                'slug' => $slug ?? Workspace::uniqueSlugFor($name),
                'created_by' => $owner->id,
            ]);
            // A workspace made by a simulated person is part of the same simulation.
            $workspace->sim_run_id = $owner->sim_run_id;
            $workspace->save();

            $workspace->memberships()->create([
                'user_id' => $owner->id,
                'role' => WorkspaceRole::Owner,
            ]);

            $project = $this->createProject->handle(
                $workspace,
                $owner,
                'DEMO',
                'Getting started',
                'A sample project to try things out. Rename it or delete it when you are ready.',
            );

            // Tenant models only work inside a workspace, so act as the new one.
            $this->current->runIn($workspace, function () use ($project, $owner): void {
                foreach (self::SAMPLE_ITEMS as $item) {
                    $this->writer->create($project, $item, $owner);
                }
            });

            return $workspace;
        });
    }
}
