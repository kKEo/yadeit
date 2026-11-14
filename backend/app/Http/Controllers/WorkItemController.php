<?php

namespace App\Http\Controllers;

use App\Enums\ItemKind;
use App\Enums\Priority;
use App\Enums\StateGroup;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Http\Presenters\ItemPresenter;
use App\Models\Activity;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Services\WorkItemWriter;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkItemController extends Controller
{
    private const SORTS = ['created_at', 'updated_at', 'priority', 'due_date', 'sequence'];

    public function __construct(private ItemPresenter $presenter, private WorkItemWriter $writer) {}

    /**
     * Filters: project (key), state (name or id), state_group, priority, kind,
     * assignee (id, "me" or "none"), label (name), parent (key or "none"),
     * q (text in the title, or an exact key), open (true hides closed items).
     * Paging: limit (default 25, max 100) and cursor. Shaping: expand, fields.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'project' => ['sometimes', 'string'],
            'state' => ['sometimes', 'string'],
            'state_group' => ['sometimes', Rule::enum(StateGroup::class)],
            'priority' => ['sometimes', Rule::in(Priority::labels())],
            'kind' => ['sometimes', Rule::enum(ItemKind::class)],
            'assignee' => ['sometimes', 'string'],
            'label' => ['sometimes', 'string'],
            'parent' => ['sometimes', 'string'],
            'q' => ['sometimes', 'string', 'max:200'],
            'open' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
            'expand' => ['sometimes', 'string'],
            'fields' => ['sometimes', 'string'],
        ]);

        $expand = array_values(array_intersect($this->csv($input['expand'] ?? ''), ItemPresenter::EXPANDABLE));
        $fields = $this->csv($input['fields'] ?? '');

        $query = WorkItem::query()->with([...ItemPresenter::LIST_RELATIONS, ...array_diff($expand, ['description'])]);
        $this->applyFilters($query, $input, $request->user()->id);
        $this->applySort($query, $input['sort'] ?? '-updated_at');

        $page = $query->cursorPaginate($input['limit'] ?? 25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (WorkItem $i) => $this->presenter->compact($i, $expand, $fields)),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Member);

        $data = $request->validate([
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            ...$this->optionalRules(),
        ]);

        $project = Project::query()->where('key', strtoupper($data['project']))->first();

        if (! $project) {
            throw ValidationException::withMessages([
                'project' => ['Unknown project. Pass a project key; available: '.(Project::query()->orderBy('key')->pluck('key')->implode(', ') ?: 'none').'.'],
            ]);
        }

        if ($project->archived_at !== null) {
            throw new ApiException(409, 'conflict', 'This project is archived. Unarchive it before adding items.');
        }

        $item = $this->writer->create($project, $data, $request->user());

        return response()->json(['data' => $this->presenter->detail($item, $request->user()->id)], 201)
            ->header('ETag', $this->etag($item));
    }

    public function show(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($item, $request->user()->id)])
            ->header('ETag', $this->etag($item));
    }

    /**
     * Send the version you last saw as `expected_version` (or If-Match) to
     * avoid overwriting someone else's change; a mismatch answers 412.
     */
    public function update(Request $request, Workspace $workspace, WorkItem $item, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Member);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'expected_version' => ['sometimes', 'integer'],
            ...$this->optionalRules(),
        ]);

        $expected = $data['expected_version'] ?? $this->versionFromIfMatch($request);
        unset($data['expected_version']);

        $item = $this->writer->update($item, $data, $request->user(), $expected);

        return response()->json(['data' => $this->presenter->detail($item, $request->user()->id)])
            ->header('ETag', $this->etag($item));
    }

    /** A soft delete: the item disappears from lists but its number is never reused. */
    public function destroy(Request $request, Workspace $workspace, WorkItem $item, CurrentWorkspace $current): Response
    {
        $current->requireRole(WorkspaceRole::Member);

        $item->recordActivity('deleted', $request->user()->id);
        $item->delete();

        return response()->noContent();
    }

    public function activity(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $limit = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']])['limit'] ?? 50;

        $page = $item->activities()->with('actor')->orderByDesc('id')->cursorPaginate((int) $limit);

        return response()->json([
            'data' => collect($page->items())->map(fn (Activity $a) => $a->summary()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function subscribe(Request $request, Workspace $workspace, WorkItem $item): Response
    {
        $item->subscribe($request->user()->id);

        return response()->noContent();
    }

    public function unsubscribe(Request $request, Workspace $workspace, WorkItem $item): Response
    {
        $item->subscribers()->detach($request->user()->id);

        return response()->noContent();
    }

    /** @return array<string, list<mixed>> */
    private function optionalRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'state' => ['sometimes', 'required'],
            'priority' => ['sometimes', Rule::in(Priority::labels())],
            'kind' => ['sometimes', Rule::enum(ItemKind::class)],
            'assignee' => ['sometimes', 'nullable'],
            'parent' => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'labels' => ['sometimes', 'array', 'max:20'],
        ];
    }

    /**
     * @param  Builder<WorkItem>  $query
     * @param  array<string, mixed>  $input
     */
    private function applyFilters(Builder $query, array $input, int $viewerId): void
    {
        if (isset($input['project'])) {
            $query->whereHas('project', fn (Builder $q) => $q->where('key', strtoupper($input['project'])));
        }

        if (isset($input['state'])) {
            $query->whereHas('state', fn (Builder $q) => is_numeric($input['state'])
                ? $q->whereKey((int) $input['state'])
                : $q->where('name', $input['state']));
        }

        if (isset($input['state_group'])) {
            $query->whereHas('state', fn (Builder $q) => $q->where('group', $input['state_group']));
        }

        if (filter_var($input['open'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('completed_at');
        }

        if (isset($input['priority'])) {
            $query->where('priority', Priority::fromLabel($input['priority'])->value);
        }

        if (isset($input['kind'])) {
            $query->where('kind', $input['kind']);
        }

        if (isset($input['assignee'])) {
            match (true) {
                $input['assignee'] === 'none' => $query->whereNull('assignee_id'),
                $input['assignee'] === 'me' => $query->where('assignee_id', $viewerId),
                default => $query->where('assignee_id', (int) $input['assignee']),
            };
        }

        if (isset($input['label'])) {
            $query->whereHas('labels', fn (Builder $q) => $q->where('labels.name', $input['label']));
        }

        if (isset($input['parent'])) {
            $input['parent'] === 'none'
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', WorkItem::findByKey($input['parent'])->id ?? 0);
        }

        if (isset($input['q']) && trim($input['q']) !== '') {
            $term = trim($input['q']);
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhere(fn (Builder $byKey) => $byKey->whereItemKey($term)));
        }
    }

    /** @param  Builder<WorkItem>  $query */
    private function applySort(Builder $query, string $sort): void
    {
        $descending = str_starts_with($sort, '-');
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTS, true)) {
            throw ValidationException::withMessages([
                'sort' => ['Sort by one of: '.implode(', ', self::SORTS).'. Prefix with - for descending.'],
            ]);
        }

        $query->orderBy($column, $descending ? 'desc' : 'asc')->orderBy('id', $descending ? 'desc' : 'asc');
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function etag(WorkItem $item): string
    {
        return '"'.$item->version.'"';
    }

    private function versionFromIfMatch(Request $request): ?int
    {
        $header = trim((string) $request->header('If-Match'), " \"\t");

        return ctype_digit($header) ? (int) $header : null;
    }
}
