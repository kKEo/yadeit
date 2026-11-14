<?php

namespace App\Http\Controllers;

use App\Enums\LinkKind;
use App\Enums\RelationType;
use App\Enums\WorkspaceRole;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The small parts of a work item: checklist (acceptance criteria), links and relations. */
class ItemPartsController extends Controller
{
    public function __construct(private CurrentWorkspace $current) {}

    public function addChecklistItem(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Member);
        $data = $request->validate(['text' => ['required', 'string', 'max:500'], 'is_done' => ['sometimes', 'boolean']]);

        $entry = $item->checklist()->create([
            ...$data,
            'workspace_id' => $item->workspace_id,
            'position' => ((int) $item->checklist()->max('position')) + 1,
        ]);

        $item->recordActivity('checklist_added', $request->user()->id, ['text' => $entry->text]);

        return response()->json(['data' => $entry->summary()], 201);
    }

    public function updateChecklistItem(Request $request, Workspace $workspace, WorkItem $item, int $entry): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Member);
        $model = $item->checklist()->whereKey($entry)->first() ?? throw new NotFoundHttpException('Checklist item not found.');

        $data = $request->validate([
            'text' => ['sometimes', 'string', 'max:500'],
            'is_done' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $wasDone = $model->is_done;
        $model->update($data);

        if ($wasDone !== $model->is_done) {
            $item->recordActivity($model->is_done ? 'checklist_checked' : 'checklist_unchecked', $request->user()->id, ['text' => $model->text]);
        }

        return response()->json(['data' => $model->summary()]);
    }

    public function removeChecklistItem(Workspace $workspace, WorkItem $item, int $entry): Response
    {
        $this->current->requireRole(WorkspaceRole::Member);
        $deleted = $item->checklist()->whereKey($entry)->delete();

        return $deleted ? response()->noContent() : throw new NotFoundHttpException('Checklist item not found.');
    }

    public function addLink(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Member);

        $data = $request->validate([
            // Only web links: a javascript: or data: URL would be dangerous to render as a link.
            'url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'kind' => ['sometimes', Rule::enum(LinkKind::class)],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $link = $item->links()->create([
            'workspace_id' => $item->workspace_id,
            // Left out, the kind is read off the address: a Figma link is a design, a pull request a PR.
            'kind' => $data['kind'] ?? LinkKind::guess($data['url'])->value,
            'url' => $data['url'],
            'title' => $data['title'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $item->recordActivity('link_added', $request->user()->id, ['url' => $link->url, 'kind' => $link->kind->value]);

        return response()->json(['data' => $link->summary()], 201);
    }

    public function removeLink(Workspace $workspace, WorkItem $item, int $link): Response
    {
        $this->current->requireRole(WorkspaceRole::Member);
        $deleted = $item->links()->whereKey($link)->delete();

        return $deleted ? response()->noContent() : throw new NotFoundHttpException('Link not found.');
    }

    /** `{type: "blocks", item: "WEB-7"}` means this item blocks WEB-7. */
    public function addRelation(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Member);

        $data = $request->validate([
            'type' => ['required', Rule::enum(RelationType::class)],
            'item' => ['required', 'string'],
        ]);

        $related = WorkItem::findByKey($data['item']);

        if (! $related || $related->id === $item->id) {
            throw ValidationException::withMessages(['item' => ['Pass the key of another item in this workspace, such as WEB-7.']]);
        }

        $relation = $item->outgoingRelations()->firstOrCreate(
            ['related_item_id' => $related->id, 'type' => $data['type']],
            ['workspace_id' => $item->workspace_id],
        );

        if ($relation->wasRecentlyCreated) {
            $item->recordActivity('relation_added', $request->user()->id, ['type' => $data['type'], 'item' => $related->key]);
        }

        return response()->json(['data' => [
            'id' => $relation->id, 'type' => $relation->type->value, 'item' => ['key' => $related->key, 'title' => $related->title],
        ]], 201);
    }

    /** Works from either side of the relation. */
    public function removeRelation(Workspace $workspace, WorkItem $item, int $relation): Response
    {
        $this->current->requireRole(WorkspaceRole::Member);

        $deleted = $item->outgoingRelations()->whereKey($relation)->delete()
            ?: $item->incomingRelations()->whereKey($relation)->delete();

        return $deleted ? response()->noContent() : throw new NotFoundHttpException('Relation not found.');
    }
}
