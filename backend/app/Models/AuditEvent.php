<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'workspace_slug', 'actor_id', 'actor_name', 'actor_type', 'token_name', 'action', 'subject', 'details', 'ip', 'created_at'])]
class AuditEvent extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function summary(bool $forOperator = false): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'subject' => $this->subject,
            'details' => $this->details,
            'actor' => $this->actor_id ? ['id' => $this->actor_id, 'name' => $this->actor_name, 'type' => $this->actor_type] : null,
            'token' => $this->token_name,
            'created_at' => $this->created_at,
            ...($forOperator ? ['workspace' => $this->workspace_slug, 'ip' => $this->ip] : []),
        ];
    }
}
