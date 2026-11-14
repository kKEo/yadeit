<?php

namespace App\Http\Controllers;

use App\Enums\ItemKind;
use App\Enums\Priority;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\FeedbackTriage;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Feedback is what people say they need, before it is work. Anyone in the
 * workspace (guests and agents too) can send it; members decide what happens
 * to it: accept it as a work item, add it to an existing item, decline it,
 * mark it as a duplicate, or snooze it. Agents do not triage.
 */
class FeedbackController extends Controller
{
    public function __construct(private CurrentWorkspace $current, private FeedbackTriage $triage) {}

    /**
     * Filters: project (key), status (new, snoozed, accepted, declined,
     * duplicate or all; default new, which leaves snoozed ones out), q (text
     * in the title). Guests see only what they sent themselves.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'project' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in([...Feedback::STATUSES, 'snoozed', 'all'])],
            'q' => ['sometimes', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $query = $this->visible($request)->with(['project', 'author', 'item.project'])->orderByDesc('id');
        $status = $input['status'] ?? Feedback::NEW;

        match ($status) {
            'all' => null,
            'snoozed' => $query->where('status', Feedback::NEW)->where('snoozed_until', '>', now()),
            Feedback::NEW => $query->where('status', Feedback::NEW)
                ->where(fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now())),
            default => $query->where('status', $status),
        };

        if (isset($input['project'])) {
            $query->whereHas('project', fn (Builder $q) => $q->where('key', strtoupper($input['project'])));
        }

        if (isset($input['q']) && trim($input['q']) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($input['q']))).'%';
            $query->whereRaw('LOWER(title) LIKE ?', [$like]);
        }

        $page = $query->cursorPaginate($input['limit'] ?? 50)->withQueryString();
        $canSeeContact = $this->isMember();

        return response()->json([
            'data' => collect($page->items())->map(fn (Feedback $f) => $f->summary($canSeeContact)),
            'next_cursor' => $page->nextCursor()?->encode(),
            // What is waiting in the inbox right now, for the badge.
            'waiting' => $this->isMember() ? $this->visible($request)->where('status', Feedback::NEW)
                ->where(fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))->count() : null,
        ]);
    }

    /** Anyone in the workspace can send feedback, including guests and agents. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $project = Project::query()->where('key', strtoupper($data['project']))->whereNull('archived_at')->first()
            ?? throw ValidationException::withMessages(['project' => ['Unknown project. Pass the key of a project that is not archived.']]);

        $feedback = Feedback::create([
            'project_id' => $project->id,
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'source' => $request->user()->isAgent() ? 'agent' : 'app',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->load($feedback)->summary()], 201);
    }

    /**
     * Makes the feedback count for a work item. Without `item` a new item is
     * created from it (optionally with `title`, `kind`, `priority`); with
     * `item` (a key) it is added to that item, raising its demand.
     */
    public function accept(Request $request, Workspace $workspace, int $feedback): JsonResponse
    {
        $data = $this->triaging($request)->validate([
            'item' => ['sometimes', 'string'],
            'title' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::enum(ItemKind::class)],
            'priority' => ['sometimes', Rule::in(Priority::labels())],
        ]);

        return $this->respond($this->triage->accept($this->find($feedback), $request->user(), $data));
    }

    public function decline(Request $request, Workspace $workspace, int $feedback): JsonResponse
    {
        $data = $this->triaging($request)->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($this->triage->decline($this->find($feedback), $request->user(), $data['reason'] ?? null));
    }

    /** Marks this feedback as saying the same as feedback `of` (an id). If that one is linked to an item, this one counts for it too. */
    public function duplicate(Request $request, Workspace $workspace, int $feedback): JsonResponse
    {
        $data = $this->triaging($request)->validate(['of' => ['required', 'integer']]);

        return $this->respond($this->triage->markDuplicate($this->find($feedback), $request->user(), $this->find((int) $data['of'])));
    }

    /** Hides the feedback from the inbox until the given time; `until: null` brings it back now. */
    public function snooze(Request $request, Workspace $workspace, int $feedback): JsonResponse
    {
        $data = $this->triaging($request)->validate(['until' => ['present', 'nullable', 'date', 'after:now']]);

        return $this->respond($this->triage->snooze($this->find($feedback), $data['until']));
    }

    /** Puts decided feedback back into the inbox. */
    public function reopen(Request $request, Workspace $workspace, int $feedback): JsonResponse
    {
        $this->triaging($request);

        return $this->respond($this->triage->reopen($this->find($feedback), $request->user()));
    }

    /**
     * Switches the project's public feedback form on or off. On: returns the
     * token of its link. Off: the old link stops working for good.
     */
    public function publicForm(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Admin);

        if ($request->user()->isAgent()) {
            throw new ApiException(403, 'not_available_to_agents', 'Agents do not decide what is public.');
        }

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $project->update(['feedback_token' => $data['enabled'] ? ($project->feedback_token ?? Str::random(40)) : null]);

        return response()->json(['data' => ['project' => $project->key, 'feedback_token' => $project->feedback_token]]);
    }

    private function triaging(Request $request): Request
    {
        if ($request->user()->isAgent()) {
            throw new ApiException(403, 'not_available_to_agents', 'Agents can send feedback but do not decide what happens to it.');
        }

        $this->current->requireRole(WorkspaceRole::Member);

        return $request;
    }

    private function isMember(): bool
    {
        return (bool) $this->current->role()?->atLeast(WorkspaceRole::Member);
    }

    /** @return Builder<Feedback> */
    private function visible(Request $request): Builder
    {
        $query = Feedback::query();

        if (! $this->isMember()) {
            $query->where('created_by', $request->user()->id);
        }

        return $query;
    }

    private function find(int $id): Feedback
    {
        return Feedback::query()->find($id) ?? throw new NotFoundHttpException('Feedback not found.');
    }

    private function load(Feedback $feedback): Feedback
    {
        return $feedback->load(['project', 'author', 'item.project']);
    }

    private function respond(Feedback $feedback): JsonResponse
    {
        return response()->json(['data' => $this->load($feedback)->summary()]);
    }
}
