<?php

namespace App\Http\Presenters;

use App\Models\Attachment;
use App\Models\ChecklistItem;
use App\Models\ItemLink;
use App\Models\ItemRelation;
use App\Models\Label;
use App\Models\WorkItem;

/**
 * Shapes work items for the API. Lists are compact by default to keep
 * responses (and agent token use) small; `expand` adds heavier parts and
 * `fields` narrows the result to named top-level keys.
 */
class ItemPresenter
{
    public const LIST_RELATIONS = ['project', 'state', 'assignee', 'delegate', 'cycle', 'labels', 'parent.project'];

    public const EXPANDABLE = ['description', 'checklist', 'links'];

    /**
     * @param  list<string>  $expand
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function compact(WorkItem $item, array $expand = [], array $fields = []): array
    {
        $data = [
            'key' => $item->key,
            'title' => $item->title,
            'state' => $item->state->summary(),
            'priority' => $item->priority->label(),
            'kind' => $item->kind->value,
            'assignee' => $item->assignee ? ['id' => $item->assignee->id, 'name' => $item->assignee->name] : null,
            // The agent doing the work; the assignee stays the accountable person.
            'delegate' => $item->delegate ? ['id' => $item->delegate->id, 'name' => $item->delegate->name] : null,
            'needs_input' => $item->needs_input,
            'cycle' => $item->cycle?->reference(),
            // How many pieces of feedback ask for this item.
            'demand' => (int) $item->demand,
            // blocked: an open item must be finished first. sub_items: how many children are done.
            ...$item->progress(),
            'labels' => $item->labels->map(fn (Label $l) => $l->summary())->values()->all(),
            'project' => ['key' => $item->project->key, 'name' => $item->project->name],
            'parent' => $item->parent?->key,
            'due_date' => $item->due_date?->toDateString(),
            'version' => $item->version,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
            'completed_at' => $item->completed_at,
        ];

        if (in_array('description', $expand, true)) {
            $data['description'] = $item->description;
        }

        if (in_array('checklist', $expand, true)) {
            $data['checklist'] = $item->checklist->map(fn (ChecklistItem $c) => $c->summary())->all();
        }

        if (in_array('links', $expand, true)) {
            $data['links'] = $item->links->map(fn (ItemLink $l) => $l->summary())->all();
        }

        return $fields === [] ? $data : array_intersect_key($data, array_flip([...$fields, 'key']));
    }

    /** @return array<string, mixed> */
    public function detail(WorkItem $item, ?int $viewerId = null): array
    {
        $item->loadMissing([
            ...self::LIST_RELATIONS, 'checklist', 'links', 'attachments', 'children.project', 'children.state',
            'outgoingRelations.related.project', 'outgoingRelations.related.state',
            'incomingRelations.item.project', 'incomingRelations.item.state',
        ]);

        return [
            ...$this->compact($item, self::EXPANDABLE),
            'children' => $item->children->map(fn (WorkItem $c) => $this->reference($c))->all(),
            'relations' => [
                ...$item->outgoingRelations->map(fn (ItemRelation $r) => [
                    'id' => $r->id, 'type' => $r->type->value, 'item' => $this->reference($r->related),
                ])->all(),
                // The same relations seen from the other side, e.g. "blocked_by".
                ...$item->incomingRelations->map(fn (ItemRelation $r) => [
                    'id' => $r->id,
                    'type' => $r->type->value === 'blocks' ? 'blocked_by' : $r->type->value,
                    'item' => $this->reference($r->item),
                ])->all(),
            ],
            'attachments' => $item->attachments->map(fn (Attachment $a) => $a->summary())->all(),
            'comment_count' => $item->comments()->count(),
            'subscribed' => $viewerId !== null && $item->subscribers()->where('users.id', $viewerId)->exists(),
        ];
    }

    /** @return array<string, mixed> */
    private function reference(WorkItem $item): array
    {
        return ['key' => $item->key, 'title' => $item->title, 'state' => $item->state->summary()];
    }
}
