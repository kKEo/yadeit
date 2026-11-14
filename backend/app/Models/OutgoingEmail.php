<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $broadcast_id
 * @property string $kind
 * @property string $to_email
 * @property string|null $to_name
 * @property string $subject
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $sent_at
 * @property-read Broadcast|null $broadcast
 */
#[Fillable(['broadcast_id', 'kind', 'to_email', 'to_name', 'subject', 'status', 'attempts', 'error', 'sent_at'])]
class OutgoingEmail extends Model
{
    use Prunable;

    public const WAITING = 'waiting';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const STATUSES = [self::WAITING, self::SENDING, self::SENT, self::FAILED];

    /** After this many tries a message stays failed until someone sends it by hand. */
    public const MAX_ATTEMPTS = 3;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Broadcast, $this> */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * The log is kept for three months.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('status', self::SENT)->where('sent_at', '<', now()->subDays(90));
    }
}
