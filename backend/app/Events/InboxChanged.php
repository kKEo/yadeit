<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Tells one person's open browsers that their inbox has something new. */
class InboxChanged implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $userId, public int $workspaceId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'inbox.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['workspace_id' => $this->workspaceId];
    }
}
