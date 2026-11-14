<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A fixed stretch of time in a project. Cycles follow each other without
 * gaps and are created and closed by the schedule, not by hand.
 *
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $closed_at
 */
#[Fillable(['workspace_id', 'project_id', 'number', 'starts_on', 'ends_on', 'closed_at'])]
class Cycle extends Model
{
    use BelongsToWorkspace;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<WorkItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->closed_at !== null => 'completed',
            $this->starts_on->isFuture() => 'upcoming',
            default => 'active',
        };
    }

    public function label(): string
    {
        return 'Cycle '.$this->number;
    }

    /** @return array<string, mixed> */
    public function reference(): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'name' => $this->label()];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $total = $this->items()->count();
        $done = $this->items()->whereHas('state', fn ($q) => $q->where('group', 'completed'))->count();
        $started = $this->items()->whereHas('state', fn ($q) => $q->where('group', 'started'))->count();

        return [
            ...$this->reference(),
            'status' => $this->status(),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'items' => ['total' => $total, 'completed' => $done, 'started' => $started],
        ];
    }
}
