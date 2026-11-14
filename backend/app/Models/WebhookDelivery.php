<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'webhook_id', 'event_id', 'event', 'payload', 'attempt', 'status_code', 'error', 'duration_ms', 'succeeded', 'created_at'])]
class WebhookDelivery extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'succeeded' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event' => $this->event,
            'attempt' => $this->attempt,
            'succeeded' => $this->succeeded,
            'status_code' => $this->status_code,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at,
        ];
    }
}
