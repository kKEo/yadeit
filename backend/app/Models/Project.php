<?php

namespace App\Models;

use App\Enums\StateGroup;
use App\Models\Concerns\BelongsToWorkspace;
use App\Support\CurrentWorkspace;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $archived_at
 * @property int|null $cycle_weeks
 * @property int|null $stale_after_days
 */
#[Fillable(['workspace_id', 'key', 'name', 'description', 'created_by', 'next_sequence', 'archived_at', 'cycle_weeks', 'stale_after_days', 'feedback_token'])]
#[Hidden(['feedback_token'])]
class Project extends Model
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        // A project-limited access token sees nothing outside its projects.
        static::addGlobalScope('token-projects', function (Builder $query): void {
            $projectIds = app(CurrentWorkspace::class)->projectIds();

            if ($projectIds !== null) {
                $query->whereIn($query->getModel()->qualifyColumn('id'), $projectIds);
            }
        });
    }

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** @return HasMany<State, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(State::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Label, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('name');
    }

    /** @return HasMany<WorkItem, $this> */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function defaultState(): State
    {
        return $this->states()->where('is_default', true)->first()
            ?? $this->states()->firstOrFail();
    }

    /** @return HasMany<Cycle, $this> */
    public function cycles(): HasMany
    {
        return $this->hasMany(Cycle::class);
    }

    /**
     * Where finished work waits for a person: the last "started" state, when
     * the project has more than one. With a single started state there is no
     * separate review step.
     */
    public function reviewState(): ?State
    {
        $started = $this->states()->where('group', StateGroup::Started)->orderBy('position')->get();

        return $started->count() > 1 ? $started->last() : null;
    }
}
