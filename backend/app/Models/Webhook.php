<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property list<string>|null $events
 * @property string $secret
 * @property bool $is_active
 * @property Carbon|null $disabled_at
 */
#[Fillable(['workspace_id', 'name', 'url', 'secret', 'events', 'is_active', 'consecutive_failures', 'disabled_at', 'created_by'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    use BelongsToWorkspace;

    /** What can be subscribed to; each is sent as "item.<name>". */
    public const EVENTS = [
        'item.created', 'item.updated', 'item.deleted', 'item.commented',
        'item.claimed', 'item.released', 'item.input_requested', 'item.input_provided',
        'item.submitted_for_review', 'item.run_failed',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'consecutive_failures' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'is_active' => 'boolean', 'disabled_at' => 'datetime'];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        return $this->is_active && ($this->events === null || in_array($event, $this->events, true));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            // null means every event.
            'events' => $this->events,
            'is_active' => $this->is_active,
            // Set when the webhook switched itself off after repeated failures.
            'disabled_at' => $this->disabled_at,
            'created_at' => $this->created_at,
        ];
    }
}
