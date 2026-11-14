<?php

namespace App\Http\Controllers;

use App\Actions\CreateProject;
use App\Enums\WorkspaceRole;
use App\Models\Cycle;
use App\Models\Label;
use App\Models\Project;
use App\Models\State;
use App\Models\Workspace;
use App\Services\CycleScheduler;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = Project::query()
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $projects->map(fn (Project $p) => $this->present($p))]);
    }

    public function store(Request $request, Workspace $workspace, CurrentWorkspace $current, CreateProject $create): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Member);

        // Keys are stored in upper case; normalise first so the uniqueness check sees the stored form.
        $request->merge(['key' => strtoupper((string) $request->input('key'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'key' => [
                'required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9]{1,9}$/',
                Rule::unique('projects', 'key')->where('workspace_id', $workspace->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ], ['key.regex' => 'The key must be 2 to 10 letters or digits and start with a letter, for example WEB.']);

        $project = $create->handle($workspace, $request->user(), $data['key'], $data['name'], $data['description'] ?? null);

        return response()->json(['data' => $this->present($project, withWorkflow: true)], 201);
    }

    public function show(Workspace $workspace, Project $project): JsonResponse
    {
        return response()->json(['data' => $this->present($project, withWorkflow: true)]);
    }

    /** The key is fixed once set, so that item keys such as WEB-12 never change. */
    public function update(Request $request, Workspace $workspace, Project $project, CurrentWorkspace $current, CycleScheduler $cycles): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Member);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'archived' => ['sometimes', 'boolean'],
            // Weeks per cycle, 1 to 8; null switches cycles off.
            'cycle_weeks' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:8'],
            // Days after which untouched open items return to the backlog; null switches it off.
            'stale_after_days' => ['sometimes', 'nullable', 'integer', 'min:7', 'max:365'],
        ]);

        if (array_key_exists('archived', $data)) {
            $project->archived_at = $data['archived'] ? ($project->archived_at ?? now()) : null;
        }

        $project->fill(collect($data)->only(['name', 'description', 'cycle_weeks', 'stale_after_days'])->all())->save();

        // Switching cycles on starts the first one right away.
        $cycles->current($project);

        return response()->json(['data' => $this->present($project, withWorkflow: true)]);
    }

    /** The project's cycles, newest first, each with how many of its items are done. */
    public function cycles(Workspace $workspace, Project $project, CycleScheduler $cycles): JsonResponse
    {
        $cycles->current($project);

        return response()->json(['data' => $project->cycles()->orderByDesc('number')->limit(30)->get()->map(fn (Cycle $c) => $c->summary())]);
    }

    public function destroy(Workspace $workspace, Project $project, CurrentWorkspace $current): Response
    {
        $current->requireRole(WorkspaceRole::Admin);

        $project->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(Project $project, bool $withWorkflow = false): array
    {
        $data = [
            'id' => $project->id,
            'key' => $project->key,
            'name' => $project->name,
            'description' => $project->description,
            'archived' => $project->archived_at !== null,
            'cycle_weeks' => $project->cycle_weeks,
            'stale_after_days' => $project->stale_after_days,
            // Token of the public feedback form's link, for members; null when the form is off.
            'feedback_token' => app(CurrentWorkspace::class)->role()?->atLeast(WorkspaceRole::Member) ? $project->feedback_token : null,
            'created_at' => $project->created_at,
        ];

        if ($withWorkflow) {
            $data['states'] = $project->states->map(fn (State $s) => [...$s->summary(), 'is_default' => $s->is_default])->all();
            $data['labels'] = $project->labels->map(fn (Label $l) => $l->summary())->all();
            // The cycle in progress, when the project uses cycles.
            $data['current_cycle'] = $project->cycle_weeks === null ? null : app(CycleScheduler::class)->current($project)?->reference();
        }

        return $data;
    }
}
