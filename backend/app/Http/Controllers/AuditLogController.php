<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A workspace's audit log: who changed access, sharing and settings, and who
 * deleted what. For admins and owners. Work on items has its own history on
 * each item.
 */
class AuditLogController extends Controller
{
    /** Filters: action (prefix, e.g. "member." or "token.created"), actor (member id). Newest first. */
    public function index(Request $request, Workspace $workspace, CurrentWorkspace $current): JsonResponse
    {
        if ($request->user()->isAgent()) {
            throw new ApiException(403, 'not_available_to_agents', 'The audit log is for people.');
        }

        $current->requireRole(WorkspaceRole::Admin);

        $input = $request->validate([
            'action' => ['sometimes', 'string', 'max:60'],
            'actor' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        return response()->json(self::page(AuditEvent::query()->where('workspace_id', $workspace->id)->orderByDesc('id'), $input));
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function page(Builder $query, array $input, bool $forOperator = false): array
    {
        if (isset($input['action'])) {
            $query->where('action', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input['action']).'%');
        }

        if (isset($input['actor'])) {
            $query->where('actor_id', (int) $input['actor']);
        }

        $page = $query->cursorPaginate($input['limit'] ?? 50)->withQueryString();

        return [
            'data' => collect($page->items())->map(fn (AuditEvent $e) => $e->summary($forOperator))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }
}
