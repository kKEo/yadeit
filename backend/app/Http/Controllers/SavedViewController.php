<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\SavedView;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Saved views: personal ones are private to their owner, shared ones are visible to the workspace. */
class SavedViewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $views = $this->visibleTo($request)->orderBy('name')->get();

        return response()->json(['data' => $views->map(fn (SavedView $v) => $v->summary())]);
    }

    public function store(Request $request, Workspace $workspace, CurrentWorkspace $current): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        if ($data['is_shared'] ?? false) {
            $current->requireRole(WorkspaceRole::Member);
        }

        $view = SavedView::create([...$data, 'filters' => $data['filters'] ?? [], 'owner_id' => $request->user()->id]);

        return response()->json(['data' => $view->refresh()->summary()], 201);
    }

    public function update(Request $request, Workspace $workspace, int $view, CurrentWorkspace $current): JsonResponse
    {
        $model = $this->editable($request, $view, $current);
        $data = $this->validated($request, creating: false);

        if (($data['is_shared'] ?? false) && ! $model->is_shared) {
            $current->requireRole(WorkspaceRole::Member);
        }

        $model->update($data);

        return response()->json(['data' => $model->summary()]);
    }

    public function destroy(Request $request, Workspace $workspace, int $view, CurrentWorkspace $current): Response
    {
        $this->editable($request, $view, $current)->delete();

        return response()->noContent();
    }

    /** @return Builder<SavedView> */
    private function visibleTo(Request $request)
    {
        return SavedView::query()->where(fn ($q) => $q
            ->where('owner_id', $request->user()->id)
            ->orWhere('is_shared', true));
    }

    /** The owner may always edit; a shared view may also be edited by admins. */
    private function editable(Request $request, int $id, CurrentWorkspace $current): SavedView
    {
        $view = $this->visibleTo($request)->whereKey($id)->first() ?? throw new NotFoundHttpException('View not found.');

        if ($view->owner_id !== $request->user()->id && ! $current->role()?->atLeast(WorkspaceRole::Admin)) {
            throw new AccessDeniedHttpException('Only the owner or an admin can change this view.');
        }

        return $view;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'is_shared' => ['sometimes', 'boolean'],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['string', 'max:200'],
            'layout' => ['sometimes', Rule::in(SavedView::LAYOUTS)],
            'group_by' => ['sometimes', Rule::in(SavedView::GROUPINGS)],
            'sort' => ['sometimes', 'string', 'regex:/^-?(created_at|updated_at|priority|due_date|sequence|demand)$/'],
        ]);

        if (isset($data['filters'])) {
            // Unknown filter names are dropped rather than stored.
            $data['filters'] = array_intersect_key($data['filters'], array_flip(SavedView::FILTERS));
        }

        return $data;
    }
}
