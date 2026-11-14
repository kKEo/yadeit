<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $archived_at
 */
#[Fillable(['workspace_id', 'key', 'name', 'description', 'created_by', 'next_sequence', 'archived_at'])]
class Project extends Model
{
    use BelongsToWorkspace;

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
}
