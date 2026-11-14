<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request->user());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'locale' => ['sometimes', Rule::in(User::LOCALES)],
            'email_notifications' => ['sometimes', 'boolean'],
        ]);

        $request->user()->update($data);

        return $this->respond($request->user());
    }

    private function respond(User $user): JsonResponse
    {
        $workspaces = $user->memberships()->with('workspace')->get()
            ->sortBy(fn (Membership $m) => $m->workspace->name)
            ->map(fn (Membership $m) => (new WorkspaceResource($m->workspace))->withRole($m->role))
            ->values();

        return response()->json([
            'data' => [
                ...(new UserResource($user))->resolve(),
                'workspaces' => $workspaces,
            ],
        ]);
    }
}
