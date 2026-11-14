<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'work_item_id', 'text', 'is_done', 'position'])]
class ChecklistItem extends Model
{
    use BelongsToWorkspace;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_done' => 'boolean'];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['id' => $this->id, 'text' => $this->text, 'is_done' => $this->is_done];
    }
}
