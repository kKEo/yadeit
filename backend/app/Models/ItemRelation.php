<?php

namespace App\Models;

use App\Enums\RelationType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed relation: for "blocks", work_item blocks related_item.
 *
 * @property RelationType $type
 */
#[Fillable(['workspace_id', 'work_item_id', 'related_item_id', 'type'])]
class ItemRelation extends Model
{
    use BelongsToWorkspace;

    protected $table = 'work_item_relations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => RelationType::class];
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'related_item_id');
    }
}
