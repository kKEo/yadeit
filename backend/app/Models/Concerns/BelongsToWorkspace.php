<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant isolation for a model with a workspace_id column: every query is
 * limited to the current workspace, and new rows are stamped with it.
 *
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $query): void {
            $current = app(CurrentWorkspace::class);

            if ($current->isUnscoped()) {
                return;
            }

            $column = $query->getModel()->qualifyColumn('workspace_id');

            $current->id() !== null
                ? $query->where($column, $current->id())
                : $query->whereRaw('1 = 0');
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') !== null) {
                return;
            }

            $workspaceId = app(CurrentWorkspace::class)->id();

            if ($workspaceId === null) {
                throw new LogicException(class_basename($model).' cannot be created outside a workspace.');
            }

            $model->setAttribute('workspace_id', $workspaceId);
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
