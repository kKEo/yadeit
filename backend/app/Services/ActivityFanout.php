<?php

namespace App\Services;

use App\Events\InboxChanged;
use App\Events\WorkspaceChanged;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Notifications\ItemActivity;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Everything that follows from a change to a work item: the realtime signal
 * to open browsers, inbox entries for the people following it, and their
 * emails. Runs after the surrounding transaction commits, so nobody is told
 * about a change that was rolled back.
 */
class ActivityFanout
{
    public function __construct(private CurrentWorkspace $current) {}

    /** @param  array<string, mixed>|null  $changes */
    public function dispatch(WorkItem $item, string $event, ?int $actorId, ?array $changes): void
    {
        // Resolved now: by the time the callback runs, the request's workspace context may be gone.
        $key = $item->key;
        $workspace = Workspace::findOrFail($item->workspace_id);

        DB::afterCommit(fn () => $this->current->runIn($workspace, function () use ($item, $key, $workspace, $event, $actorId, $changes): void {
            WorkspaceChanged::dispatch($workspace->id, $key, $event, $actorId);
            app(WebhookDispatcher::class)->itemChanged($item, $workspace, $event, $actorId, $changes);

            foreach ($this->inboxEntries($item, $event, $actorId, $changes) as [$userId, $inboxEvent, $details]) {
                $this->deliver($item, $workspace, $userId, $inboxEvent, $actorId, $details);
            }
        }));
    }

    /**
     * Who should hear about this, and as what. The person who made the change
     * is never notified about their own action.
     *
     * @param  array<string, mixed>|null  $changes
     * @return list<array{0: int, 1: string, 2: array<string, mixed>|null}>
     */
    private function inboxEntries(WorkItem $item, string $event, ?int $actorId, ?array $changes): array
    {
        $entries = [];
        $assigned = null;

        $becameAssignee = ($event === 'created' && $item->assignee_id !== null)
            || ($event === 'updated' && isset($changes['assignee']) && $item->assignee_id !== null);

        if ($becameAssignee && $item->assignee_id !== $actorId) {
            $assigned = $item->assignee_id;
            $entries[] = [$assigned, 'assigned', null];
        }

        $followers = fn () => $item->subscribers()->pluck('users.id')
            ->reject(fn (int $id) => $id === $actorId || $id === $assigned);

        if ($event === 'commented') {
            foreach ($followers() as $userId) {
                $entries[] = [$userId, 'commented', null];
            }
        }

        if ($event === 'updated' && isset($changes['state'])) {
            foreach ($followers() as $userId) {
                $entries[] = [$userId, 'state_changed', $changes['state']];
            }
        }

        // An agent's question or finished work goes to the people who answer for it.
        if (in_array($event, ['input_requested', 'submitted_for_review', 'run_failed'], true)) {
            $owners = [$item->assignee_id, $item->delegate?->owner_id, User::find($actorId)?->owner_id];

            $audience = $followers()->merge($owners)->filter()->unique()
                ->reject(fn (int $id) => $id === $actorId);

            foreach ($audience as $userId) {
                $entries[] = [$userId, $event, null];
            }
        }

        return $entries;
    }

    /** @param  array<string, mixed>|null  $details */
    private function deliver(WorkItem $item, Workspace $workspace, int $userId, string $event, ?int $actorId, ?array $details): void
    {
        // Following an item does not outlive the membership.
        if (! DB::table('workspace_memberships')->where('workspace_id', $item->workspace_id)->where('user_id', $userId)->exists()) {
            return;
        }

        $entry = InboxItem::create([
            'workspace_id' => $item->workspace_id,
            'user_id' => $userId,
            'work_item_id' => $item->id,
            'actor_id' => $actorId,
            'event' => $event,
            'details' => $details,
            'created_at' => now(),
        ]);

        InboxChanged::dispatch($userId, $item->workspace_id);

        $user = User::find($userId);

        if ($user?->email_notifications && $user->hasVerifiedEmail()) {
            $user->notify(ItemActivity::for($entry, $workspace->slug));
        }
    }
}
