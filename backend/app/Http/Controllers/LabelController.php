<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Label;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LabelController extends Controller
{
    public function index(Workspace $workspace, Project $project): JsonResponse
    {
        return response()->json(['data' => $project->labels->map(fn (Label $l) => $l->summary())]);
    }

    public function store(Request $request, Workspace $workspace, Project $project, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Member);

        $label = $project->labels()->create([
            ...$this->validated($request, $project),
            'workspace_id' => $project->workspace_id,
        ]);

        return response()->json(['data' => $label->summary()], 201);
    }

    public function update(Request $request, Workspace $workspace, Project $project, int $label, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Member);
        $model = $this->find($project, $label);
        $model->update($this->validated($request, $project, $model));

        return response()->json(['data' => $model->summary()]);
    }

    public function destroy(Workspace $workspace, Project $project, int $label, CurrentWorkspace $current): Response
    {
        $current->requireRole(WorkspaceRole::Member);
        $this->find($project, $label)->delete();

        return response()->noContent();
    }

    private function find(Project $project, int $id): Label
    {
        return $project->labels()->whereKey($id)->first() ?? throw new NotFoundHttpException('Label not found.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?Label $label = null): array
    {
        return $request->validate([
            'name' => [$label ? 'sometimes' : 'required', 'string', 'max:50',
                Rule::unique('labels', 'name')->where('project_id', $project->id)->ignore($label?->id)],
            'color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
    }
}
