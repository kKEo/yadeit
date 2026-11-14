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
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property Priority $priority
 * @property ItemKind $kind
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 * @property bool $needs_input
 * @property int $demand
 * @property-read string $key
 */
#[Fillable([
    'workspace_id', 'project_id', 'sequence', 'title', 'description', 'state_id', 'priority', 'kind',
    'assignee_id', 'delegate_id', 'needs_input', 'cycle_id', 'created_by', 'parent_id', 'due_date', 'version', 'completed_at',
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

    /** @var array<string, mixed> */
    protected $attributes = ['needs_input' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'kind' => ItemKind::class,
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
            'needs_input' => 'boolean',
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

    /** @return BelongsTo<Cycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /**
     * The agent doing the work, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /**
     * Items not blocked by any open item. Blockers count even when the
     * caller's token cannot see their project.
     *
     * @param  Builder<WorkItem>  $query
     */
    public function scopeUnblocked(Builder $query): void
    {
        $query->whereDoesntHave('incomingRelations', fn (Builder $relation) => $relation
            ->where('type', 'blocks')
            ->whereHas('item', fn (Builder $blocker) => $blocker->withoutGlobalScope('token-projects')->whereNull('completed_at')));
    }

    /**
     * Adds what a list row shows beyond the item itself: whether an open item
     * blocks it, and how many of its sub-items are done.
     *
     * @param  Builder<WorkItem>  $query
     */
    public function scopeWithProgress(Builder $query): void
    {
        $query
            ->withExists(['incomingRelations as is_blocked' => fn (Builder $relation) => $relation
                ->where('type', 'blocks')
                ->whereHas('item', fn (Builder $blocker) => $blocker->withoutGlobalScope('token-projects')->whereNull('completed_at'))])
            ->withCount(['children', 'children as children_done_count' => fn (Builder $child) => $child->whereNotNull('completed_at')]);
    }

    /** @return array{blocked: bool, sub_items: array{total: int, done: int}|null} */
    public function progress(): array
    {
        // Lists load these in one query (withProgress); a single item works them out on demand.
        $blocked = $this->getAttribute('is_blocked') ?? ($this->openBlockerKeys() !== []);
        $total = $this->getAttribute('children_count') ?? $this->children()->count();
        $done = $this->getAttribute('children_done_count') ?? $this->children()->whereNotNull('completed_at')->count();

        return [
            'blocked' => (bool) $blocked,
            'sub_items' => $total > 0 ? ['total' => (int) $total, 'done' => (int) $done] : null,
        ];
    }

    /** @return list<string> keys of the open items blocking this one */
    public function openBlockerKeys(): array
    {
        return $this->incomingRelations()->where('type', 'blocks')->get()
            ->map(fn (ItemRelation $relation) => WorkItem::withoutGlobalScope('token-projects')
                ->with(['project' => fn ($project) => $project->withoutGlobalScope('token-projects')])
                ->find($relation->work_item_id))
            ->filter(fn (?WorkItem $blocker) => $blocker !== null && $blocker->completed_at === null)
            ->map(fn (WorkItem $blocker) => $blocker->key)
            ->values()->all();
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
        // Changes made with an access token carry it, so the history shows not only who but through what.
        $caller = request()->user();
        $token = $caller instanceof User && $caller->id === $actorId && request()->bearerToken() !== null
            ? $caller->currentAccessToken()
            : null;
        $token = $token instanceof PersonalAccessToken ? $token : null;

        $this->activities()->create([
            'workspace_id' => $this->workspace_id,
            'actor_id' => $actorId,
            'token_id' => $token?->id,
            'token_name' => $token?->name,
            'event' => $event,
            'diff' => $changes,
            'created_at' => now(),
        ]);

        app(ActivityFanout::class)->dispatch($this, $event, $actorId, $changes);
    }
}
