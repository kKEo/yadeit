<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Project::query()->orderBy('name')->get()->map(fn (Project $p) => $this->present($p)),
        ]);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json(['data' => $this->present($project)]);
    }

    /** @return array<string, mixed> */
    private function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'key' => $project->key,
            'name' => $project->name,
            'description' => $project->description,
            'created_at' => $project->created_at,
        ];
    }
}
