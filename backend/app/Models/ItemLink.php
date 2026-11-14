<?php

namespace App\Models;

use App\Enums\LinkKind;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property LinkKind $kind
 */
#[Fillable(['workspace_id', 'work_item_id', 'kind', 'url', 'title', 'created_by'])]
class ItemLink extends Model
{
    use BelongsToWorkspace;

    protected $table = 'work_item_links';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => LinkKind::class];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind->value, 'url' => $this->url, 'title' => $this->title];
    }
}
