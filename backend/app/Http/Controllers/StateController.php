<?php

namespace App\Http\Controllers;

use App\Enums\StateGroup;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\State;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** States can be renamed and added freely; each one belongs to a fixed group. */
class StateController extends Controller
{
    public function index(Workspace $workspace, Project $project): JsonResponse
    {
        return response()->json(['data' => $project->states->map(fn (State $s) => $this->present($s))]);
    }

    public function store(Request $request, Workspace $workspace, Project $project, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Admin);
        $data = $this->validated($request, $project);

        $state = $project->states()->create([
            ...$data,
            'workspace_id' => $project->workspace_id,
            'position' => ((int) $project->states()->max('position')) + 1,
        ]);

        return response()->json(['data' => $this->present($state)], 201);
    }

    public function update(Request $request, Workspace $workspace, Project $project, int $state, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Admin);
        $model = $this->find($project, $state);
        $data = $this->validated($request, $project, $model);

        DB::transaction(function () use ($project, $model, $data): void {
            if (($data['is_default'] ?? false) === true) {
                $project->states()->where('id', '!=', $model->id)->update(['is_default' => false]);
            }

            $model->update($data);
        });

        return response()->json(['data' => $this->present($model)]);
    }

    public function destroy(Workspace $workspace, Project $project, int $state, CurrentWorkspace $current): Response
    {
        $current->requireRole(WorkspaceRole::Admin);
        $model = $this->find($project, $state);

        if ($model->is_default) {
            throw new ApiException(409, 'conflict', 'The default state cannot be deleted. Make another state the default first.');
        }

        if ($model->workItems()->withTrashed()->exists()) {
            throw new ApiException(409, 'conflict', 'This state still has items. Move them to another state first.');
        }

        $model->delete();

        return response()->noContent();
    }

    private function find(Project $project, int $id): State
    {
        return $project->states()->whereKey($id)->first() ?? throw new NotFoundHttpException('State not found.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?State $state = null): array
    {
        $required = $state ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:50', Rule::unique('states', 'name')->where('project_id', $project->id)->ignore($state?->id)],
            'group' => [$required, Rule::enum(StateGroup::class)],
            'color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(State $state): array
    {
        return [...$state->summary(), 'position' => $state->position, 'is_default' => $state->is_default];
    }
}
