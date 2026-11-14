<?php

namespace App\Services;

use App\Enums\Priority;
use App\Exceptions\ApiException;
use App\Models\Label;
use App\Models\Project;
use App\Models\State;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place work items are created and changed, so that key allocation,
 * version bumps, history and subscriptions always happen together.
 *
 * References are accepted the way people and agents write them: a state or
 * label by name or id, an assignee by id or "me", a parent by key.
 */
class WorkItemWriter
{
    /** @param  array<string, mixed>  $data */
    public function create(Project $project, array $data, User $actor): WorkItem
    {
        return DB::transaction(function () use ($project, $data, $actor): WorkItem {
            // Lock the project row so two concurrent creates cannot get the same number.
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $sequence = $locked->next_sequence;
            $locked->update(['next_sequence' => $sequence + 1]);

            $state = array_key_exists('state', $data)
                ? $this->resolveState($project, $data['state'])
                : $project->defaultState();

            $item = new WorkItem([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'sequence' => $sequence,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'state_id' => $state->id,
                'priority' => isset($data['priority']) ? Priority::fromLabel($data['priority']) : Priority::None,
                'kind' => $data['kind'] ?? 'task',
                'assignee_id' => $this->resolveAssignee($project, $data['assignee'] ?? null, $actor),
                'parent_id' => $this->resolveParent($data['parent'] ?? null, null)?->id,
                'due_date' => $data['due_date'] ?? null,
                'created_by' => $actor->id,
                'version' => 1,
                'completed_at' => $state->group->isClosed() ? now() : null,
            ]);
            $item->save();

            if (! empty($data['labels'])) {
                $item->labels()->sync($this->resolveLabels($project, $data['labels']));
            }

            $item->subscribe($actor->id);
            $item->subscribe($item->assignee_id);
            $item->recordActivity('created', $actor->id);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the keys present are changed.
     */
    public function update(WorkItem $item, array $data, User $actor, ?int $expectedVersion = null): WorkItem
    {
        return DB::transaction(function () use ($item, $data, $actor, $expectedVersion): WorkItem {
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($expectedVersion !== null && $expectedVersion !== $item->version) {
                throw new ApiException(412, 'version_mismatch',
                    'This item was changed by someone else. Load it again and retry.',
                    ['current_version' => $item->version, 'expected_version' => $expectedVersion]);
            }

            $project = $item->project;
            $changes = [];

            foreach (['title', 'description', 'kind', 'due_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $from = $field === 'due_date' ? $item->due_date?->toDateString() : $item->getRawOriginal($field);

                    if ($from !== $data[$field]) {
                        $changes[$field] = ['from' => $from, 'to' => $data[$field]];
                        $item->setAttribute($field, $data[$field]);
                    }
                }
            }

            if (array_key_exists('priority', $data)) {
                $priority = Priority::fromLabel($data['priority']);

                if ($priority !== $item->priority) {
                    $changes['priority'] = ['from' => $item->priority->label(), 'to' => $priority->label()];
                    $item->priority = $priority;
                }
            }

            if (array_key_exists('state', $data)) {
                $state = $this->resolveState($project, $data['state']);

                if ($state->id !== $item->state_id) {
                    $changes['state'] = ['from' => $item->state->name, 'to' => $state->name];
                    $item->state_id = $state->id;
                    $item->completed_at = $state->group->isClosed() ? ($item->completed_at ?? now()) : null;
                }
            }

            if (array_key_exists('assignee', $data)) {
                $assigneeId = $this->resolveAssignee($project, $data['assignee'], $actor);

                if ($assigneeId !== $item->assignee_id) {
                    $changes['assignee'] = [
                        'from' => $item->assignee?->name,
                        'to' => $assigneeId ? User::find($assigneeId)?->name : null,
                    ];
                    $item->assignee_id = $assigneeId;
                }
            }

            if (array_key_exists('parent', $data)) {
                $parent = $this->resolveParent($data['parent'], $item);

                if ($parent?->id !== $item->parent_id) {
                    $changes['parent'] = ['from' => $item->parent?->key, 'to' => $parent?->key];
                    $item->parent_id = $parent?->id;
                }
            }

            if (array_key_exists('labels', $data)) {
                $before = $item->labels()->orderBy('name')->pluck('name')->all();
                $item->labels()->sync($this->resolveLabels($project, $data['labels'] ?? []));
                $after = $item->labels()->orderBy('name')->pluck('name')->all();

                if ($before !== $after) {
                    $changes['labels'] = ['from' => $before, 'to' => $after];
                }
            }

            if ($changes === []) {
                return $item;
            }

            $item->version++;
            $item->save();
            $item->unsetRelations();

            $item->subscribe($item->assignee_id);
            $item->recordActivity('updated', $actor->id, $changes);

            return $item;
        });
    }

    private function resolveState(Project $project, mixed $reference): State
    {
        $states = $project->states()->get();

        $state = is_numeric($reference)
            ? $states->firstWhere('id', (int) $reference)
            : $states->first(fn (State $s) => strcasecmp($s->name, (string) $reference) === 0);

        if (! $state) {
            $this->fail('state', 'Unknown state. Available in this project: '.$states->pluck('name')->implode(', ').'.');
        }

        return $state;
    }

    private function resolveAssignee(Project $project, mixed $reference, User $actor): ?int
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $userId = $reference === 'me' ? $actor->id : (is_numeric($reference) ? (int) $reference : null);

        $isMember = $userId !== null && DB::table('workspace_memberships')
            ->where('workspace_id', $project->workspace_id)
            ->where('user_id', $userId)
            ->exists();

        if (! $isMember) {
            $this->fail('assignee', 'The assignee must be a member of this workspace: pass a member id or "me".');
        }

        return $userId;
    }

    private function resolveParent(mixed $reference, ?WorkItem $child): ?WorkItem
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $parent = WorkItem::findByKey((string) $reference);

        if (! $parent) {
            $this->fail('parent', 'Unknown parent item. Pass an item key such as WEB-12.');
        }

        // Walk up from the new parent: reaching the child itself would make a loop.
        for ($ancestor = $parent, $depth = 0; $child && $ancestor && $depth < 50; $ancestor = $ancestor->parent, $depth++) {
            if ($ancestor->id === $child->id) {
                $this->fail('parent', 'An item cannot be its own parent or ancestor.');
            }
        }

        return $parent;
    }

    /**
     * @param  array<int, mixed>  $references
     * @return list<int>
     */
    private function resolveLabels(Project $project, array $references): array
    {
        $labels = $project->labels()->get();
        $ids = [];

        foreach (Arr::wrap($references) as $reference) {
            $label = is_numeric($reference)
                ? $labels->firstWhere('id', (int) $reference)
                : $labels->first(fn (Label $l) => strcasecmp($l->name, (string) $reference) === 0);

            if (! $label) {
                $this->fail('labels', "Unknown label [{$reference}]. Available in this project: ".($labels->pluck('name')->implode(', ') ?: 'none').'.');
            }

            $ids[] = $label->id;
        }

        return array_values(array_unique($ids));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
