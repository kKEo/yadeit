<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The column is called diff because Eloquent keeps its own $changes property.
 *
 * @property array<string, mixed>|null $diff
 */
#[Fillable(['workspace_id', 'work_item_id', 'actor_id', 'token_id', 'token_name', 'event', 'diff', 'created_at'])]
class Activity extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['diff' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'changes' => $this->diff,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name, 'type' => $this->actor->type->value] : null,
            // The access token the change came through; null for the web app.
            'token' => $this->token_name,
            'created_at' => $this->created_at,
        ];
    }
}
