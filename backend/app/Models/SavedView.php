<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, string> $filters
 */
#[Fillable(['workspace_id', 'owner_id', 'name', 'is_shared', 'filters', 'layout', 'group_by', 'sort'])]
class SavedView extends Model
{
    use BelongsToWorkspace;

    /** Filter names a view may store; the same names the item list accepts. */
    public const FILTERS = ['project', 'state', 'state_group', 'priority', 'kind', 'assignee', 'label', 'q', 'open'];

    public const LAYOUTS = ['list', 'board'];

    public const GROUPINGS = ['state', 'priority', 'assignee', 'kind', 'none'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['filters' => 'array', 'is_shared' => 'boolean'];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_shared' => $this->is_shared,
            'owner_id' => $this->owner_id,
            'filters' => (object) $this->filters,
            'layout' => $this->layout,
            'group_by' => $this->group_by,
            'sort' => $this->sort,
        ];
    }
}
