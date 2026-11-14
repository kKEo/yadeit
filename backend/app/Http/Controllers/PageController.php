<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Docs: Markdown pages of the workspace or of one project. Members write
 * them; guests read published ones. Drafts are visible to members.
 */
class PageController extends Controller
{
    public function __construct(private CurrentWorkspace $current) {}

    /** Filters: project (key, or "none" for workspace-wide pages), status, q (text in the title). Bodies are left out; read one page for its text. */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'project' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in([Page::DRAFT, Page::PUBLISHED])],
            'q' => ['sometimes', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $query = $this->visible()->with(['project', 'editor'])->orderByDesc('updated_at')->orderByDesc('id');

        if (isset($input['project'])) {
            $input['project'] === 'none'
                ? $query->whereNull('project_id')
                : $query->whereHas('project', fn (Builder $q) => $q->where('key', strtoupper($input['project'])));
        }

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (isset($input['q']) && trim($input['q']) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($input['q']))).'%';
            $query->whereRaw('LOWER(title) LIKE ?', [$like]);
        }

        $page = $query->cursorPaginate($input['limit'] ?? 50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Page $p) => $p->summary()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Member);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'project' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in([Page::DRAFT, Page::PUBLISHED])],
        ]);

        $page = Page::create([
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'status' => $data['status'] ?? Page::DRAFT,
            'project_id' => $this->project($data['project'] ?? null)?->id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $page->load(['project', 'editor'])->summary(withBody: true)], 201);
    }

    public function show(Workspace $workspace, int $page): JsonResponse
    {
        return response()->json(['data' => $this->find($page)->summary(withBody: true)]);
    }

    /**
     * Send `expected_version` to avoid overwriting someone else's edit (412 on
     * a mismatch). `public: true` gives a published page a link that works
     * without signing in; `public: false` or unpublishing takes it away.
     */
    public function update(Request $request, Workspace $workspace, int $page): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Member);
        $model = $this->find($page);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'project' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in([Page::DRAFT, Page::PUBLISHED])],
            'public' => ['sometimes', 'boolean'],
            'expected_version' => ['sometimes', 'integer'],
        ]);

        if (isset($data['expected_version']) && (int) $data['expected_version'] !== $model->version) {
            throw new ApiException(412, 'version_mismatch', 'This page was changed by someone else. Load it again and retry.',
                ['current_version' => $model->version, 'expected_version' => (int) $data['expected_version']]);
        }

        if (array_key_exists('public', $data) && $request->user()->isAgent()) {
            throw new ApiException(403, 'not_available_to_agents', 'Agents do not decide what is public. Ask a person to share the page.');
        }

        $model->fill(collect($data)->only(['title', 'body', 'status'])->all());

        if (array_key_exists('project', $data)) {
            $model->project_id = $this->project($data['project'])?->id;
        }

        if (array_key_exists('public', $data)) {
            if ($data['public'] && $model->status !== Page::PUBLISHED) {
                throw ValidationException::withMessages(['public' => ['Publish the page first: only published pages can be shared by link.']]);
            }

            $model->public_token = $data['public'] ? ($model->public_token ?? Str::random(40)) : null;
        }

        // A draft is never reachable from outside, whatever it was before.
        if ($model->status !== Page::PUBLISHED) {
            $model->public_token = null;
        }

        if ($model->isDirty()) {
            $model->version++;
            $model->updated_by = $request->user()->id;
            $model->save();
        }

        return response()->json(['data' => $model->load(['project', 'editor'])->summary(withBody: true)]);
    }

    public function destroy(Workspace $workspace, int $page): Response
    {
        $this->current->requireRole(WorkspaceRole::Member);
        $model = $this->find($page);

        // A deleted page must not stay readable from outside.
        $model->update(['public_token' => null]);
        $model->delete();

        return response()->noContent();
    }

    /** A published page with a public link, readable by anyone who has the link. No sign-in. */
    public function publicShow(string $token, CurrentWorkspace $current): JsonResponse
    {
        $page = $current->runUnscoped(fn () => Page::query()
            ->where('public_token', $token)
            ->where('status', Page::PUBLISHED)
            ->first());

        if (! $page) {
            throw new NotFoundHttpException('This page does not exist or is no longer shared.');
        }

        return response()->json(['data' => [
            'title' => $page->title,
            'body' => $page->body,
            'updated_at' => $page->updated_at,
        ]]);
    }

    /** @return Builder<Page> */
    private function visible(): Builder
    {
        $query = Page::query();

        // Guests are outside the team: they read what is published, not what is being drafted.
        if (! $this->current->role()?->atLeast(WorkspaceRole::Member)) {
            $query->where('status', Page::PUBLISHED);
        }

        return $query;
    }

    private function find(int $id): Page
    {
        return $this->visible()->with(['project', 'editor'])->find($id) ?? throw new NotFoundHttpException('Page not found.');
    }

    private function project(?string $key): ?Project
    {
        if ($key === null || $key === '' || $key === 'none') {
            return null;
        }

        return Project::query()->where('key', strtoupper($key))->first()
            ?? throw ValidationException::withMessages([
                'project' => ['Unknown project. Pass a project key, or null for a workspace-wide page.'],
            ]);
    }
}
