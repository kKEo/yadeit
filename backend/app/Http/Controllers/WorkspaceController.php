<?php

namespace App\Http\Controllers;

use App\Actions\ProvisionWorkspace;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Http\Resources\WorkspaceResource;
use App\Models\Membership;
use App\Models\Workspace;
use App\Services\Billing;
use App\Support\CurrentWorkspace;
use App\Support\Plans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()->memberships()->with('workspace')->get()
            ->sortBy(fn (Membership $m) => $m->workspace->name)
            ->map(fn (Membership $m) => (new WorkspaceResource($m->workspace))->withRole($m->role))
            ->values();

        return response()->json(['data' => $workspaces]);
    }

    public function store(Request $request, ProvisionWorkspace $provision): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => $this->slugRules(),
        ]);

        $workspace = $provision->handle($request->user(), $data['name'], $data['slug'] ?? null);

        return (new WorkspaceResource($workspace))->withRole(WorkspaceRole::Owner)
            ->response()->setStatusCode(201);
    }

    public function show(Workspace $workspace, CurrentWorkspace $current): WorkspaceResource
    {
        return (new WorkspaceResource($workspace))->withRole($current->role());
    }

    public function update(Request $request, Workspace $workspace, CurrentWorkspace $current): WorkspaceResource
    {
        $current->requireRole(WorkspaceRole::Admin);

        $workspace->update($request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'slug' => $this->slugRules($workspace),
        ]));

        return (new WorkspaceResource($workspace))->withRole($current->role());
    }

    public function destroy(Workspace $workspace, CurrentWorkspace $current): Response
    {
        $current->requireRole(WorkspaceRole::Owner);

        // Deleting must not leave a subscription behind that keeps charging.
        if (Plans::billingEnabled() && $workspace->subscribed(Billing::SUBSCRIPTION) && ! $workspace->planSubscription()?->canceled()) {
            throw new ApiException(409, 'active_subscription', 'This workspace has a paid plan. Cancel it under Settings → Manage billing, then delete the workspace.');
        }

        $workspace->delete();

        return response()->noContent();
    }

    /** @return list<mixed> */
    private function slugRules(?Workspace $ignore = null): array
    {
        return [
            'sometimes', 'string', 'min:2', 'max:40',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::notIn(Workspace::RESERVED_SLUGS),
            Rule::unique('workspaces', 'slug')->ignore($ignore?->id),
        ];
    }
}
