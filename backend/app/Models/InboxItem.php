<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One notification for one person about one work item.
 *
 * @property array<string, mixed>|null $details
 * @property Carbon|null $read_at
 * @property Carbon|null $snoozed_until
 */
#[Fillable(['workspace_id', 'user_id', 'work_item_id', 'actor_id', 'event', 'details', 'read_at', 'snoozed_until', 'created_at'])]
class InboxItem extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['details' => 'array', 'read_at' => 'datetime', 'snoozed_until' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'details' => $this->details,
            'item' => ['key' => $this->workItem->key, 'title' => $this->workItem->title],
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
            'read' => $this->read_at !== null,
            'snoozed_until' => $this->snoozed_until,
            'created_at' => $this->created_at,
        ];
    }
}
