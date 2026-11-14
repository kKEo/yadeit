<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'key', 'name', 'description', 'created_by'])]
class Project extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;
}
