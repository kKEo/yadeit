<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Project;
use App\Models\WorkItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a project's cycles running by themselves: there is always a current
 * cycle, and when one ends it is closed, the next one starts the day after,
 * and whatever was not finished moves along.
 */
class CycleScheduler
{
    /** The cycle that contains today, created if the project uses cycles and has none. */
    public function current(Project $project, ?Carbon $today = null): ?Cycle
    {
        if ($project->cycle_weeks === null) {
            return null;
        }

        $today = ($today ?? now())->copy()->startOfDay();
        $this->advance($project, $today);

        return $project->cycles()->whereNull('closed_at')->orderBy('number')->first()
            ?? $this->open($project, $today->copy()->startOfWeek(), 1);
    }

    /**
     * Closes every cycle that has ended and opens its successor.
     *
     * @return int how many cycles were closed
     */
    public function advance(Project $project, ?Carbon $today = null): int
    {
        if ($project->cycle_weeks === null) {
            return 0;
        }

        $today = ($today ?? now())->copy()->startOfDay();
        $closed = 0;

        // A guard against a schedule that did not run for a long time.
        while ($closed < 60) {
            $ended = $project->cycles()->whereNull('closed_at')->where('ends_on', '<', $today->toDateString())->orderBy('number')->first();

            if (! $ended) {
                break;
            }

            DB::transaction(function () use ($project, $ended): void {
                $next = $project->cycles()->where('number', $ended->number + 1)->first()
                    ?? $this->open($project, $ended->ends_on->copy()->addDay(), $ended->number + 1);

                $unfinished = WorkItem::query()->where('cycle_id', $ended->id)->whereNull('completed_at')->get();

                foreach ($unfinished as $item) {
                    $item->update(['cycle_id' => $next->id]);
                    $item->recordActivity('rolled_over', null, ['cycle' => ['from' => $ended->label(), 'to' => $next->label()]]);
                }

                $ended->update(['closed_at' => now()]);
            });

            $closed++;
        }

        return $closed;
    }

    private function open(Project $project, Carbon $start, int $number): Cycle
    {
        return $project->cycles()->create([
            'workspace_id' => $project->workspace_id,
            'number' => $number,
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->addWeeks((int) $project->cycle_weeks)->subDay()->toDateString(),
        ]);
    }
}
