<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Project;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The feedback form anyone can fill in when a project has switched it on.
 * No sign-in. It reveals the project's name and nothing else, and what is
 * sent lands in the feedback inbox, never straight in the work.
 */
class PublicFeedbackController extends Controller
{
    public function __construct(private CurrentWorkspace $current) {}

    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => ['project' => $this->project($token)->name]]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $project = $this->project($token);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
            // People never see this field; programs that fill in every field give themselves away.
            'website' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        // Answer a spam robot exactly like a person, so it learns nothing, but keep nothing.
        if (! empty($data['website'])) {
            return response()->json(['data' => ['received' => true]], 201);
        }

        $this->current->runIn($project->workspace, fn () => Feedback::create([
            'project_id' => $project->id,
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'source' => 'public',
            'submitter_name' => $data['name'] ?? null,
            'submitter_email' => isset($data['email']) ? strtolower($data['email']) : null,
        ]));

        return response()->json(['data' => ['received' => true]], 201);
    }

    private function project(string $token): Project
    {
        $project = $this->current->runUnscoped(fn () => Project::query()
            ->where('feedback_token', $token)
            ->whereNull('archived_at')
            ->with('workspace')
            ->first());

        return $project ?? throw new NotFoundHttpException('This feedback form does not exist or is closed.');
    }
}
