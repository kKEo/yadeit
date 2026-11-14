<?php

namespace App\Models;

use App\Enums\RunEntryType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property RunEntryType $type
 */
#[Fillable(['workspace_id', 'agent_run_id', 'type', 'body', 'created_at'])]
class AgentRunEntry extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => RunEntryType::class, 'created_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['id' => $this->id, 'type' => $this->type->value, 'body' => $this->body, 'created_at' => $this->created_at];
    }
}
