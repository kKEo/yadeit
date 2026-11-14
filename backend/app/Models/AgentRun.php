<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property RunStatus $status
 * @property Carbon $started_at
 * @property Carbon $last_activity_at
 * @property Carbon|null $ended_at
 */
#[Fillable(['workspace_id', 'work_item_id', 'agent_id', 'status', 'started_at', 'last_activity_at', 'ended_at'])]
class AgentRun extends Model
{
    use BelongsToWorkspace;

    /** An active run with no sign of life for this long is shown as stale. */
    public const STALE_AFTER_MINUTES = 30;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    /** @return HasMany<AgentRunEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AgentRunEntry::class)->orderBy('id');
    }

    public function isStale(): bool
    {
        return $this->status === RunStatus::Active
            && $this->last_activity_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'agent' => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->name] : null,
            'status' => $this->status->value,
            // Still marked active, but silent for a while: the agent may have crashed.
            'stale' => $this->isStale(),
            'started_at' => $this->started_at,
            'last_activity_at' => $this->last_activity_at,
            'ended_at' => $this->ended_at,
            'entries' => $this->entries->map(fn (AgentRunEntry $entry) => $entry->summary())->all(),
        ];
    }
}
