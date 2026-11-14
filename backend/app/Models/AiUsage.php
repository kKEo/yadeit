<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'user_id', 'feature', 'model', 'input_tokens', 'output_tokens', 'created_at'])]
class AiUsage extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $table = 'ai_usage';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
