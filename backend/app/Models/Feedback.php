<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $triaged_at
 */
#[Fillable([
    'workspace_id', 'project_id', 'title', 'body', 'source', 'status', 'submitter_name', 'submitter_email', 'created_by',
    'work_item_id', 'duplicate_of_id', 'decline_reason', 'snoozed_until', 'triaged_by', 'triaged_at',
])]
class Feedback extends Model
{
    use BelongsToWorkspace;

    public const NEW = 'new';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const DUPLICATE = 'duplicate';

    public const STATUSES = [self::NEW, self::ACCEPTED, self::DECLINED, self::DUPLICATE];

    protected $table = 'feedback';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::NEW, 'source' => 'app'];

    protected static function booted(): void
    {
        // A project-limited access token sees the feedback of its projects only.
        static::addGlobalScope('token-projects', function (Builder $query): void {
            $projectIds = app(CurrentWorkspace::class)->projectIds();

            if ($projectIds !== null) {
                $query->whereIn($query->getModel()->qualifyColumn('project_id'), $projectIds);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snoozed_until' => 'datetime', 'triaged_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Feedback, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function isSnoozed(): bool
    {
        return $this->status === self::NEW && $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /** @return array<string, mixed> */
    public function summary(bool $withContact = true): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'project' => ['key' => $this->project->key, 'name' => $this->project->name],
            'source' => $this->source,
            'status' => $this->status,
            'snoozed_until' => $this->isSnoozed() ? $this->snoozed_until : null,
            // Who sent it: a member, or the name and address someone left on the public form.
            'from' => array_filter([
                'name' => $this->author ? $this->author->name : $this->submitter_name,
                'email' => $withContact && ! $this->author ? $this->submitter_email : null,
                'member_id' => $this->author?->id,
            ], fn (mixed $v) => $v !== null),
            'item' => $this->item ? ['key' => $this->item->key, 'title' => $this->item->title, 'demand' => $this->item->demand] : null,
            'duplicate_of' => $this->duplicate_of_id,
            'decline_reason' => $this->decline_reason,
            'created_at' => $this->created_at,
            'triaged_at' => $this->triaged_at,
        ];
    }
}
