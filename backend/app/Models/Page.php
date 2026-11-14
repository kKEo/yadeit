<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Markdown document of the workspace or of one project. A draft is
 * work in progress; a published page is the version people should rely on.
 * A published page can also get a link that works without signing in.
 */
#[Fillable(['workspace_id', 'project_id', 'title', 'body', 'status', 'public_token', 'version', 'created_by', 'updated_by'])]
#[Hidden(['public_token'])]
class Page extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT, 'version' => 1];

    protected static function booted(): void
    {
        // A project-limited access token sees the pages of its projects only, not workspace-wide ones.
        static::addGlobalScope('token-projects', function (Builder $query): void {
            $projectIds = app(CurrentWorkspace::class)->projectIds();

            if ($projectIds !== null) {
                $query->whereIn($query->getModel()->qualifyColumn('project_id'), $projectIds);
            }
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPublic(): bool
    {
        return $this->public_token !== null && $this->status === self::PUBLISHED;
    }

    /** @return array<string, mixed> */
    public function summary(bool $withBody = false): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'project' => $this->project ? ['key' => $this->project->key, 'name' => $this->project->name] : null,
            'status' => $this->status,
            // The token of the link that works without signing in; null when there is none.
            'public_token' => $this->isPublic() ? $this->public_token : null,
            'version' => $this->version,
            'updated_by' => $this->editor ? ['id' => $this->editor->id, 'name' => $this->editor->name] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        return $withBody ? [...$data, 'body' => $this->body] : $data;
    }
}
