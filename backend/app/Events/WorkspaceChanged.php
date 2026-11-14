<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells open browsers that something in a workspace changed, so they can
 * refresh what they show. Carries identifiers only, never content: clients
 * re-read through the API, which applies the usual access rules.
 */
class WorkspaceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $workspaceId,
        public string $itemKey,
        public string $event,
        public ?int $actorId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('workspace.'.$this->workspaceId);
    }

    public function broadcastAs(): string
    {
        return 'item.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['key' => $this->itemKey, 'event' => $this->event, 'actor_id' => $this->actorId];
    }
}
