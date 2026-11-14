<?php

namespace App\Models;

use App\Enums\ItemKind;
use App\Enums\Priority;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\ActivityFanout;
use App\Support\CurrentWorkspace;
use Database\Factories\WorkItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Priority $priority
 * @property ItemKind $kind
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 * @property-read string $key
 */
#[Fillable([
    'workspace_id', 'project_id', 'sequence', 'title', 'description', 'state_id', 'priority', 'kind',
    'assignee_id', 'created_by', 'parent_id', 'due_date', 'version', 'completed_at',
])]
class WorkItem extends Model
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        // A project-limited access token sees nothing outside its projects.
        static::addGlobalScope('token-projects', function (Builder $query): void {
            $projectIds = app(CurrentWorkspace::class)->projectIds();

            if ($projectIds !== null) {
                $query->whereIn($query->getModel()->qualifyColumn('project_id'), $projectIds);
            }
        });
    }

    /** @use HasFactory<WorkItemFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'kind' => ItemKind::class,
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    /** The human-readable identifier, for example "WEB-143". */
    public function getKeyAttribute(): string
    {
        return $this->project->key.'-'.$this->sequence;
    }

    /**
     * Finds an item by its key within the current workspace.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWhereItemKey(Builder $query, string $key): void
    {
        if (! preg_match('/^([A-Za-z][A-Za-z0-9]*)-(\d{1,9})$/', $key, $m)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('sequence', (int) $m[2])
            ->whereHas('project', fn (Builder $q) => $q->where('key', strtoupper($m[1])));
    }

    public static function findByKey(string $key): ?self
    {
        return self::query()->whereItemKey($key)->first();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<WorkItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklist(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ItemLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(ItemLink::class);
    }

    /** @return HasMany<ItemRelation, $this> */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(ItemRelation::class);
    }

    /** @return HasMany<ItemRelation, $this> */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(ItemRelation::class, 'related_item_id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'subscriptions');
    }

    public function subscribe(?int $userId): void
    {
        if ($userId !== null) {
            $this->subscribers()->syncWithPivotValues([$userId], ['created_at' => now()], detaching: false);
        }
    }

    /** @param  array<string, mixed>|null  $changes */
    public function recordActivity(string $event, ?int $actorId, ?array $changes = null): void
    {
        $this->activities()->create([
            'workspace_id' => $this->workspace_id,
            'actor_id' => $actorId,
            'event' => $event,
            'diff' => $changes,
            'created_at' => now(),
        ]);

        app(ActivityFanout::class)->dispatch($this, $event, $actorId, $changes);
    }
}
