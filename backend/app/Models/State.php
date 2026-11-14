<?php

namespace App\Models;

use App\Enums\StateGroup;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property StateGroup $group
 */
#[Fillable(['workspace_id', 'project_id', 'name', 'group', 'color', 'position', 'is_default'])]
class State extends Model
{
    use BelongsToWorkspace;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['group' => StateGroup::class, 'is_default' => 'boolean'];
    }

    /** @return HasMany<WorkItem, $this> */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'group' => $this->group->value, 'color' => $this->color];
    }
}
