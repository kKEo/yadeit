<?php

namespace App\Services;

use App\Jobs\SendWebhook;
use App\Models\Comment;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WorkItem;
use App\Models\Workspace;
use Illuminate\Support\Str;

/** Turns a change to a work item into an event for every webhook that asked for it. */
class WebhookDispatcher
{
    /** @param  array<string, mixed>|null  $changes */
    public function itemChanged(WorkItem $item, Workspace $workspace, string $activity, ?int $actorId, ?array $changes): void
    {
        // Smaller changes (a checklist tick, a new link) all go out as "item.updated".
        $event = in_array('item.'.$activity, Webhook::EVENTS, true) ? 'item.'.$activity : 'item.updated';

        $webhooks = Webhook::query()->where('is_active', true)->get()->filter(fn (Webhook $w) => $w->wants($event));

        if ($webhooks->isEmpty()) {
            return;
        }

        $payload = $this->payload($item, $workspace, $event, $activity, $actorId, $changes);

        foreach ($webhooks as $webhook) {
            SendWebhook::dispatch($workspace->id, $webhook->id, $payload['id'], $event, $payload);
        }
    }

    public function ping(Webhook $webhook, Workspace $workspace): string
    {
        $payload = ['id' => (string) Str::uuid(), 'event' => 'ping', 'created_at' => now()->toIso8601String(), 'workspace' => $workspace->slug];

        SendWebhook::dispatch($workspace->id, $webhook->id, $payload['id'], 'ping', $payload);

        return $payload['id'];
    }

    /**
     * @param  array<string, mixed>|null  $changes
     * @return array<string, mixed>
     */
    private function payload(WorkItem $item, Workspace $workspace, string $event, string $activity, ?int $actorId, ?array $changes): array
    {
        $item = WorkItem::withTrashed()->with(['project', 'state', 'assignee', 'delegate'])->find($item->id) ?? $item;
        $actor = $actorId ? User::find($actorId) : null;
        $person = fn (?User $user) => $user ? ['id' => $user->id, 'name' => $user->name, 'type' => $user->type->value] : null;
        $comment = isset($changes['comment_id']) ? Comment::find($changes['comment_id']) : null;

        return array_filter([
            // The same on every retry of this event: use it to ignore repeats.
            'id' => (string) Str::uuid(),
            'event' => $event,
            'activity' => $activity,
            'created_at' => now()->toIso8601String(),
            'workspace' => $workspace->slug,
            'actor' => $person($actor),
            'item' => [
                'key' => $item->key,
                'title' => $item->title,
                'project' => $item->project->key,
                'state' => ['name' => $item->state->name, 'group' => $item->state->group->value],
                'priority' => $item->priority->label(),
                'kind' => $item->kind->value,
                'assignee' => $person($item->assignee),
                'delegate' => $person($item->delegate),
                'needs_input' => $item->needs_input,
                'url' => rtrim((string) config('app.frontend_url'), '/').'/w/'.$workspace->slug.'/items/'.$item->key,
            ],
            'changes' => $changes,
            'comment' => $comment ? ['id' => $comment->id, 'body' => $comment->body] : null,
        ], fn (mixed $value) => $value !== null);
    }
}
