<?php

namespace App\Actions;

use App\Enums\StateGroup;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** Creates a project with the preset workflow, so it is usable without any setup. */
class CreateProject
{
    /** Name, group, colour, default. */
    private const PRESET_STATES = [
        ['Triage', StateGroup::Triage, '#a78bfa', false],
        ['Backlog', StateGroup::Backlog, '#94a3b8', false],
        ['Todo', StateGroup::Unstarted, '#64748b', true],
        ['In progress', StateGroup::Started, '#f59e0b', false],
        ['In review', StateGroup::Started, '#3b82f6', false],
        ['Done', StateGroup::Completed, '#22c55e', false],
        ['Cancelled', StateGroup::Cancelled, '#ef4444', false],
    ];

    public function handle(Workspace $workspace, ?User $creator, string $key, string $name, ?string $description = null): Project
    {
        return DB::transaction(function () use ($workspace, $creator, $key, $name, $description): Project {
            $project = Project::create([
                'workspace_id' => $workspace->id,
                'key' => strtoupper($key),
                'name' => $name,
                'description' => $description,
                'created_by' => $creator?->id,
            ]);

            foreach (self::PRESET_STATES as $position => [$stateName, $group, $color, $isDefault]) {
                $project->states()->create([
                    'workspace_id' => $workspace->id,
                    'name' => $stateName,
                    'group' => $group,
                    'color' => $color,
                    'position' => $position,
                    'is_default' => $isDefault,
                ]);
            }

            return $project;
        });
    }
}
