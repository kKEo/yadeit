<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'work_item_id', 'user_id', 'body', 'edited_at'])]
class Comment extends Model
{
    use BelongsToWorkspace;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            'created_at' => $this->created_at,
            'edited_at' => $this->edited_at,
        ];
    }
}
