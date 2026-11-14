<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'project_id', 'name', 'color'])]
class Label extends Model
{
    use BelongsToWorkspace;

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color];
    }
}
