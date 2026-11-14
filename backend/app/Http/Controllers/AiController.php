<?php

namespace App\Http\Controllers;

use App\Ai\AiPrompt;
use App\Ai\AiService;
use App\Ai\Prompts;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\Comment;
use App\Models\Feedback;
use App\Models\Page;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Built-in AI help. Every endpoint returns a suggestion and changes nothing:
 * a person reads it and decides. AI is off until a workspace admin switches
 * it on, because using it sends the text involved to the model provider.
 * Members use it; guests and agents do not.
 */
class AiController extends Controller
{
    /** More text than this in one request is refused rather than quietly cut short. */
    private const MAX_CHARACTERS = 300_000;

    public function __construct(private AiService $ai, private CurrentWorkspace $current) {}

    /** Whether AI is on and usable here, and how much of the month's allowance is used. */
    public function status(Workspace $workspace): JsonResponse
    {
        return response()->json(['data' => $this->ai->status($workspace)]);
    }

    /**
     * Admins switch AI on or off, set the workspace's own provider key
     * (`api_key: null` goes back to the instance's key) and its monthly token
     * limit. The key is write-only: it is never returned.
     */
    public function configure(Request $request, Workspace $workspace): JsonResponse
    {
        if ($request->user()->isAgent()) {
            throw new ApiException(403, 'not_available_to_agents', 'AI settings are managed by people.');
        }

        $this->current->requireRole(WorkspaceRole::Admin);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'api_key' => ['sometimes', 'nullable', 'string', 'min:20', 'max:300'],
            'monthly_token_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $workspace->forceFill(array_filter([
            'ai_enabled' => $data['enabled'] ?? null,
        ], fn (mixed $v) => $v !== null));

        if (array_key_exists('api_key', $data)) {
            $workspace->ai_api_key = $data['api_key'];
        }

        if (array_key_exists('monthly_token_limit', $data)) {
            $workspace->ai_monthly_token_limit = $data['monthly_token_limit'];
        }

        $workspace->save();

        return response()->json(['data' => $this->ai->status($workspace)]);
    }

    /** Drafts a work item from one sentence: title, description, kind, priority, labels and acceptance criteria. */
    public function draftItem(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $this->allowed($request)->validate([
            'project' => ['required', 'string'],
            'text' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $project = Project::query()->where('key', strtoupper($data['project']))->first()
            ?? throw ValidationException::withMessages(['project' => ['Unknown project. Pass a project key.']]);

        $labels = $project->labels()->orderBy('name')->pluck('name')->all();

        return $this->suggest($request, $workspace, Prompts::draftItem($data['text'], $project->name, $labels));
    }

    /** Proposes sub-items that together cover the item. An empty list means it is small enough as it is. */
    public function splitItem(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $this->allowed($request);

        return $this->suggest($request, $workspace, Prompts::splitItem(
            $item->title, $item->description, $item->children()->pluck('title')->all(),
        ));
    }

    /** Summarises an item and its discussion: where it stands, what was decided, what is still open. */
    public function summariseItem(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $this->allowed($request);

        $comments = $item->comments()->with('author')->orderBy('id')->get()
            ->map(fn (Comment $c) => ['author' => $c->author->name ?? 'someone', 'body' => $c->body])
            ->all();

        return $this->suggest($request, $workspace, Prompts::summariseItem($item->title, $item->description, $comments));
    }

    /** Summarises a page: a few sentences and its key points. */
    public function summarisePage(Request $request, Workspace $workspace, int $page): JsonResponse
    {
        $this->allowed($request);

        $model = Page::query()->find($page) ?? throw new NotFoundHttpException('Page not found.');

        if (trim((string) $model->body) === '') {
            throw new ApiException(422, 'validation_failed', 'This page has no text to summarise yet.');
        }

        return $this->suggest($request, $workspace, Prompts::summarisePage($model->title, $model->body));
    }

    /**
     * Suggests what to do with a piece of feedback: whether it duplicates
     * other feedback in the inbox, whether an open item already covers it,
     * and the kind, priority and labels a new item would get.
     */
    public function triageFeedback(Request $request, Workspace $workspace, int $feedback): JsonResponse
    {
        $this->allowed($request);

        $model = Feedback::query()->with('project')->find($feedback) ?? throw new NotFoundHttpException('Feedback not found.');

        $others = Feedback::query()
            ->where('project_id', $model->project_id)->where('id', '!=', $model->id)
            ->whereIn('status', [Feedback::NEW, Feedback::ACCEPTED])->whereNull('duplicate_of_id')
            ->orderByDesc('id')->limit(40)->get(['id', 'title']);

        $items = WorkItem::query()->with('project')
            ->where('project_id', $model->project_id)->whereNull('completed_at')
            ->orderByDesc('updated_at')->limit(80)->get();

        $prompt = Prompts::triageFeedback(
            $model->title, $model->body,
            $others->map(fn (Feedback $f) => ['id' => $f->id, 'title' => $f->title])->all(),
            $items->map(fn (WorkItem $i) => ['key' => $i->key, 'title' => $i->title])->all(),
            $model->project->labels()->orderBy('name')->pluck('name')->all(),
        );

        $answer = $this->ai->run($workspace, $request->user(), $this->checked($prompt));

        // The model picks from lists; anything it names that is not on them is dropped, not trusted.
        if (! $others->contains('id', $answer['duplicate_of'] ?? null)) {
            $answer['duplicate_of'] = null;
        }

        if (! $items->contains(fn (WorkItem $i) => $i->key === ($answer['existing_item'] ?? null))) {
            $answer['existing_item'] = null;
        }

        return response()->json(['data' => $answer]);
    }

    private function allowed(Request $request): Request
    {
        if ($request->user()->isAgent()) {
            throw new ApiException(403, 'not_available_to_agents', 'The built-in AI features are for people. Agents bring their own model.');
        }

        $this->current->requireRole(WorkspaceRole::Member);

        return $request;
    }

    private function suggest(Request $request, Workspace $workspace, AiPrompt $prompt): JsonResponse
    {
        return response()->json(['data' => $this->ai->run($workspace, $request->user(), $this->checked($prompt))]);
    }

    private function checked(AiPrompt $prompt): AiPrompt
    {
        if (mb_strlen($prompt->content) > self::MAX_CHARACTERS) {
            throw new ApiException(422, 'ai_too_long', 'There is too much text here to send in one go. Nothing was cut and nothing was sent.');
        }

        return $prompt;
    }
}
