<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Plans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The operator area: for the people who run the installation. It sees every
 * workspace and account, but only their outline (names, counts, dates) —
 * never items, pages or comments.
 */
class OperatorController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json(['data' => [
            'workspaces' => Workspace::query()->count(),
            'suspended_workspaces' => Workspace::query()->whereNotNull('suspended_at')->count(),
            'people' => User::query()->where('type', 'human')->count(),
            'agents' => User::query()->where('type', 'agent')->count(),
            'items' => DB::table('work_items')->whereNull('deleted_at')->count(),
            'signups_last_7_days' => User::query()->where('type', 'human')->where('created_at', '>=', now()->subDays(7))->count(),
        ]]);
    }

    /** Filters: q (name or web address), suspended (true/false). */
    public function workspaces(Request $request): JsonResponse
    {
        $input = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'suspended' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $query = Workspace::query()->withCount('memberships')->orderByDesc('id');

        if (isset($input['q'])) {
            $like = $this->like($input['q']);
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(slug) LIKE ?', [$like]));
        }

        if (isset($input['suspended'])) {
            filter_var($input['suspended'], FILTER_VALIDATE_BOOLEAN) ? $query->whereNotNull('suspended_at') : $query->whereNull('suspended_at');
        }

        $page = $query->cursorPaginate($input['limit'] ?? 50)->withQueryString();
        $items = DB::table('work_items')->whereNull('deleted_at')
            ->whereIn('workspace_id', collect($page->items())->pluck('id'))
            ->groupBy('workspace_id')->pluck(DB::raw('COUNT(*)'), 'workspace_id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Workspace $w) => $this->presentWorkspace($w, (int) ($items[$w->id] ?? 0))),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** Suspend or restore a workspace, or put it on a plan. A suspended workspace is closed to its members. */
    public function updateWorkspace(Request $request, int $id): JsonResponse
    {
        $model = Workspace::query()->withCount('memberships')->findOrFail($id);

        $data = $request->validate([
            'suspended' => ['sometimes', 'boolean'],
            'plan' => ['sometimes', Rule::in(Plans::keys())],
        ]);

        if (array_key_exists('suspended', $data)) {
            $model->suspended_at = $data['suspended'] ? ($model->suspended_at ?? now()) : null;
        }

        if (isset($data['plan'])) {
            $model->plan = $data['plan'];
        }

        $model->save();

        return response()->json(['data' => $this->presentWorkspace($model, DB::table('work_items')->where('workspace_id', $model->id)->whereNull('deleted_at')->count())]);
    }

    /** Filters: q (name or email), suspended (true/false). People only; agents belong to their workspace. */
    public function users(Request $request): JsonResponse
    {
        $input = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'suspended' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $query = User::query()->where('type', 'human')->withCount('memberships')->orderByDesc('id');

        if (isset($input['q'])) {
            $like = $this->like($input['q']);
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]));
        }

        if (isset($input['suspended'])) {
            filter_var($input['suspended'], FILTER_VALIDATE_BOOLEAN) ? $query->whereNotNull('suspended_at') : $query->whereNull('suspended_at');
        }

        $page = $query->cursorPaginate($input['limit'] ?? 50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => $this->presentUser($u)),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** Suspend or restore an account. Suspending also ends its sessions and tokens. */
    public function updateUser(Request $request, int $id): JsonResponse
    {
        $model = User::query()->where('type', 'human')->withCount('memberships')->findOrFail($id);
        $data = $request->validate(['suspended' => ['required', 'boolean']]);

        if ($data['suspended'] && $model->id === $request->user()->id) {
            throw new ApiException(409, 'conflict', 'You cannot suspend your own account.');
        }

        $model->forceFill(['suspended_at' => $data['suspended'] ? ($model->suspended_at ?? now()) : null])->save();

        if ($data['suspended']) {
            $model->tokens()->delete();
            DB::table('sessions')->where('user_id', $model->id)->delete();
        }

        return response()->json(['data' => $this->presentUser($model)]);
    }

    /** The audit log of the whole installation. Filters: workspace (web address), action (prefix), actor (id). */
    public function audit(Request $request): JsonResponse
    {
        $input = $request->validate([
            'workspace' => ['sometimes', 'string'],
            'action' => ['sometimes', 'string', 'max:60'],
            'actor' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $query = AuditEvent::query()->orderByDesc('id');

        if (isset($input['workspace'])) {
            $query->where('workspace_slug', $input['workspace']);
        }

        return response()->json(AuditLogController::page($query, $input, forOperator: true));
    }

    private function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($term))).'%';
    }

    /** @return array<string, mixed> */
    private function presentWorkspace(Workspace $workspace, int $items): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'plan' => $workspace->plan,
            'members' => (int) $workspace->memberships_count,
            'items' => $items,
            'suspended_at' => $workspace->suspended_at,
            'created_at' => $workspace->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'is_operator' => (bool) $user->is_operator,
            'workspaces' => (int) $user->memberships_count,
            'suspended_at' => $user->suspended_at,
            'created_at' => $user->created_at,
        ];
    }
}
