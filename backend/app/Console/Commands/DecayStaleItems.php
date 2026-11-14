<?php

namespace App\Console\Commands;

use App\Enums\StateGroup;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Services\AgentRuns;
use App\Support\CurrentWorkspace;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * In projects that ask for it, open items nobody has touched for a while go
 * back to the backlog, so that "to do" and "in progress" show what is really
 * going on. Items waiting for a person's answer are left alone: they are
 * stale because of the wait, and they already stand out.
 */
class DecayStaleItems extends Command
{
    protected $signature = 'items:decay';

    protected $description = 'Return open items that nobody touched for a long time to the backlog';

    public function handle(CurrentWorkspace $current, AgentRuns $runs): int
    {
        $moved = 0;

        foreach (Workspace::query()->cursor() as $workspace) {
            $moved += $current->runIn($workspace, fn () => Project::query()
                ->whereNotNull('stale_after_days')
                ->whereNull('archived_at')
                ->get()
                ->sum(fn (Project $project) => $this->decay($project, $runs)));
        }

        $this->info("Moved {$moved} stale item(s) to the backlog.");

        return self::SUCCESS;
    }

    private function decay(Project $project, AgentRuns $runs): int
    {
        $backlog = $project->states()->where('group', StateGroup::Backlog)->orderBy('position')->first();

        // Without a backlog state there is nowhere to put them.
        if (! $backlog) {
            return 0;
        }

        $stale = WorkItem::query()
            ->where('project_id', $project->id)
            ->whereNull('completed_at')
            ->where('needs_input', false)
            ->where('updated_at', '<', now()->subDays((int) $project->stale_after_days))
            ->whereHas('state', fn (Builder $q) => $q->whereIn('group', [StateGroup::Unstarted, StateGroup::Started]))
            ->with('state')
            ->get();

        foreach ($stale as $item) {
            DB::transaction(function () use ($item, $backlog, $runs, $project): void {
                $changes = ['state' => ['from' => $item->state->name, 'to' => $backlog->name], 'idle_days' => (int) $project->stale_after_days];

                // An agent that went quiet no longer holds the item; a person stays assigned.
                if ($item->delegate_id !== null) {
                    $runs->stop($item, $item->delegate_id);
                    $changes['delegate'] = ['from' => $item->delegate?->name, 'to' => null];
                }

                $item->forceFill(['state_id' => $backlog->id, 'delegate_id' => null, 'version' => $item->version + 1])->save();
                $item->recordActivity('decayed', null, $changes);
            });
        }

        return $stale->count();
    }
}
