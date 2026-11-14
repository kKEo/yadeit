<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\RunEntryType;
use App\Enums\StateGroup;
use App\Enums\UserType;
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
    public function __construct(private AgentRuns $runs) {}

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

            $this->guardAgent($actor, $data, $state);

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
                'delegate_id' => $this->resolveDelegate($project, $data['delegate'] ?? null, $actor)?->id,
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

            $this->guardAgent($actor, $data, array_key_exists('state', $data) ? $this->resolveState($project, $data['state']) : null, $item);

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

            if (array_key_exists('delegate', $data)) {
                $delegate = $this->resolveDelegate($project, $data['delegate'], $actor);

                if ($delegate?->id !== $item->delegate_id) {
                    if ($item->delegate_id !== null) {
                        $this->runs->stop($item, $item->delegate_id);
                    }

                    $changes['delegate'] = ['from' => $item->delegate?->name, 'to' => $delegate?->name];
                    $item->delegate_id = $delegate?->id;
                }
            }

            if (array_key_exists('needs_input', $data) && (bool) $data['needs_input'] !== $item->needs_input) {
                $changes['needs_input'] = ['from' => $item->needs_input, 'to' => (bool) $data['needs_input']];
                $item->needs_input = (bool) $data['needs_input'];

                if (! $item->needs_input) {
                    $this->runs->resume($item);
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

    /**
     * Takes an item to work on, atomically: of two callers racing for the
     * same item exactly one wins and the other gets 409 `already_claimed`.
     * An agent becomes the delegate, a person the assignee. Work that has
     * not started moves to the project's first "started" state.
     */
    public function claim(WorkItem $item, User $actor): WorkItem
    {
        return DB::transaction(function () use ($item, $actor): WorkItem {
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $group = $item->state->group;

            if ($group->isClosed() || $group === StateGroup::Triage) {
                throw new ApiException(409, 'not_claimable', $group === StateGroup::Triage
                    ? 'This item is still in triage. A person has to accept it first.'
                    : 'This item is closed.');
            }

            $blockers = $item->openBlockerKeys();

            if ($blockers !== []) {
                throw new ApiException(409, 'blocked', 'This item is blocked by '.implode(', ', $blockers).'. Pick another one from the ready list.',
                    ['blocked_by' => $blockers]);
            }

            $field = $actor->isAgent() ? 'delegate_id' : 'assignee_id';
            $holderId = $item->getAttribute($field);

            if ($holderId !== null && $holderId !== $actor->id) {
                $holder = User::query()->whereKey($holderId)->value('name') ?? 'someone else';

                throw new ApiException(409, 'already_claimed', "This item is already taken by {$holder}. Pick another one from the ready list.",
                    ['claimed_by' => ['id' => $holderId, 'name' => $holder]]);
            }

            $changes = [];

            if ($holderId === null) {
                $changes[$actor->isAgent() ? 'delegate' : 'assignee'] = ['from' => null, 'to' => $actor->name];
                $item->setAttribute($field, $actor->id);
            }

            if (in_array($group, [StateGroup::Backlog, StateGroup::Unstarted], true)) {
                $started = $item->project->states()->where('group', StateGroup::Started)->orderBy('position')->first();

                if ($started) {
                    $changes['state'] = ['from' => $item->state->name, 'to' => $started->name];
                    $item->state_id = $started->id;
                }
            }

            if ($item->needs_input) {
                $changes['needs_input'] = ['from' => true, 'to' => false];
                $item->needs_input = false;
            }

            if ($actor->isAgent()) {
                $this->runs->open($item, $actor);
            }

            return $this->commit($item, 'claimed', $actor, $changes);
        });
    }

    /** Gives a claimed item back: an agent stops being its delegate, a person its assignee. */
    public function release(WorkItem $item, User $actor): WorkItem
    {
        return DB::transaction(function () use ($item, $actor): WorkItem {
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $field = $actor->isAgent() ? 'delegate_id' : 'assignee_id';

            if ($item->getAttribute($field) !== $actor->id) {
                throw new ApiException(409, 'not_claimed_by_you', 'You do not hold this item, so there is nothing to release.');
            }

            $item->setAttribute($field, null);

            if ($actor->isAgent()) {
                $this->runs->stop($item, $actor->id);
            }

            return $this->commit($item, 'released', $actor, [
                $actor->isAgent() ? 'delegate' : 'assignee' => ['from' => $actor->name, 'to' => null],
            ]);
        });
    }

    /** Pauses the work on a question: adds it as a comment and flags the item as needing input. */
    public function askForInput(WorkItem $item, User $actor, string $question): WorkItem
    {
        return DB::transaction(function () use ($item, $actor, $question): WorkItem {
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $comment = $item->comments()->create([
                'workspace_id' => $item->workspace_id,
                'user_id' => $actor->id,
                'body' => $question,
            ]);

            $changes = ['comment_id' => $comment->id];

            if (! $item->needs_input) {
                $changes['needs_input'] = ['from' => false, 'to' => true];
                $item->needs_input = true;
            }

            $this->runs->record($item, $actor, RunEntryType::Question, $question);

            return $this->commit($item, 'input_requested', $actor, $changes);
        });
    }

    /**
     * Hands finished work to a person: moves the item to the project's review
     * state (its last "started" state) with an optional summary comment.
     */
    public function submitForReview(WorkItem $item, User $actor, ?string $summary): WorkItem
    {
        return DB::transaction(function () use ($item, $actor, $summary): WorkItem {
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->state->group->isClosed()) {
                throw new ApiException(409, 'not_claimable', 'This item is closed.');
            }

            $review = $item->project->reviewState()
                ?? throw new ApiException(409, 'no_review_state',
                    "Project {$item->project->key} has no review state. An admin can add a second state in the \"started\" group, for example \"In review\".");

            $changes = [];

            if ($review->id !== $item->state_id) {
                $changes['state'] = ['from' => $item->state->name, 'to' => $review->name];
                $item->state_id = $review->id;
            }

            if ($item->needs_input) {
                $changes['needs_input'] = ['from' => true, 'to' => false];
                $item->needs_input = false;
            }

            if ($summary !== null && trim($summary) !== '') {
                $comment = $item->comments()->create([
                    'workspace_id' => $item->workspace_id,
                    'user_id' => $actor->id,
                    'body' => $summary,
                ]);
                $changes['comment_id'] = $comment->id;
            }

            $this->runs->record($item, $actor, RunEntryType::Response, trim((string) $summary) !== '' ? (string) $summary : 'Submitted for review.');

            return $this->commit($item, 'submitted_for_review', $actor, $changes);
        });
    }

    /** A person has replied on an item that was waiting for input: the wait is over. */
    public function inputProvided(WorkItem $item, User $actor): void
    {
        DB::transaction(function () use ($item, $actor): void {
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->needs_input) {
                $item->needs_input = false;
                $this->runs->resume($item);
                $this->commit($item, 'input_provided', $actor, ['needs_input' => ['from' => true, 'to' => false]]);
            }
        });
    }

    /**
     * What an agent may not decide: it does not close work (a person reviews
     * it), does not choose who is accountable, and does not hand work to
     * another agent.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardAgent(User $actor, array $data, ?State $state, ?WorkItem $item = null): void
    {
        if (! $actor->isAgent()) {
            return;
        }

        if ($state?->group->isClosed() && $state->id !== $item?->state_id) {
            throw new ApiException(403, 'agents_cannot_close',
                "Agents do not move items to \"{$state->name}\". Submit the work for review (POST items/{key}/submit-for-review) and a person will close it.");
        }

        if (array_key_exists('assignee', $data) && $this->changesAssignee($data['assignee'], $item)) {
            throw new ApiException(403, 'agents_cannot_assign', 'Agents do not choose the assignee. Suggest it in a comment.');
        }

        if (array_key_exists('delegate', $data)) {
            $wanted = $data['delegate'];
            $self = $wanted === 'me' || (is_numeric($wanted) && (int) $wanted === $actor->id)
                || (is_string($wanted) && strcasecmp($wanted, $actor->name) === 0);
            $releasing = ($wanted === null || $wanted === '') && ($item === null || $item->delegate_id === null || $item->delegate_id === $actor->id);

            if (! $self && ! $releasing) {
                throw new ApiException(403, 'agents_cannot_assign', 'An agent can take an item itself (claim) or give its own back (release), not hand work to others.');
            }
        }
    }

    private function changesAssignee(mixed $wanted, ?WorkItem $item): bool
    {
        $wantedId = ($wanted === null || $wanted === '') ? null : (is_numeric($wanted) ? (int) $wanted : -1);

        return $wantedId !== $item?->assignee_id;
    }

    /** @param  array<string, mixed>  $changes */
    private function commit(WorkItem $item, string $event, User $actor, array $changes): WorkItem
    {
        if ($changes === []) {
            return $item;
        }

        $item->version++;
        $item->save();
        $item->unsetRelations();

        $item->subscribe($actor->id);
        $item->recordActivity($event, $actor->id, $changes);

        return $item;
    }

    /** A delegate is always an agent of this workspace: an id, a name, or "me" when an agent delegates to itself. */
    private function resolveDelegate(Project $project, mixed $reference, User $actor): ?User
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $agents = User::query()
            ->where('type', UserType::Agent)
            ->whereIn('id', DB::table('workspace_memberships')->where('workspace_id', $project->workspace_id)->select('user_id'))
            ->get();

        $agent = match (true) {
            $reference === 'me' => $agents->firstWhere('id', $actor->id),
            is_numeric($reference) => $agents->firstWhere('id', (int) $reference),
            default => $agents->first(fn (User $a) => strcasecmp($a->name, (string) $reference) === 0),
        };

        if (! $agent) {
            $this->fail('delegate', 'The delegate must be an agent of this workspace. Available: '.($agents->pluck('name')->implode(', ') ?: 'none').'.');
        }

        return $agent;
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
