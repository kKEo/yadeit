<?php

namespace App\Simulator;

use App\Console\Commands\DecayStaleItems;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\AgentRuns;
use App\Services\CycleScheduler;
use App\Simulator\Models\SimRun;
use App\Support\CurrentWorkspace;

/**
 * What the scheduler does every night, for the workspaces of a run and on the
 * run's own calendar. The speed-up shrinks people's days, not the server's
 * clock, so cycles would never end and items never go stale during a run;
 * here the same code is called with the simulated date instead of today's.
 */
class SimCalendar
{
    public function __construct(
        private CurrentWorkspace $current,
        private CycleScheduler $cycles,
        private AgentRuns $agentRuns,
        private SimMetrics $metrics,
    ) {}

    /** Plays every midnight between the last one played and the given simulated day. */
    public function advanceTo(SimRun $run, int $day): void
    {
        // A runner cannot make the run skip more than a month at once.
        $day = min($day, $run->sim_day + 31);

        while ($run->sim_day < $day && $run->started_at !== null) {
            $started = microtime(true);
            $next = $run->sim_day + 1;
            // Taken by one runner only when several report the same midnight.
            if (SimRun::query()->whereKey($run->id)->where('sim_day', $run->sim_day)->update(['sim_day' => $next]) === 0) {
                $run->refresh();

                continue;
            }

            $run->sim_day = $next;
            $today = $run->started_at->copy()->addDays($next);
            $closed = $moved = 0;

            foreach (Workspace::query()->where('sim_run_id', $run->id)->cursor() as $workspace) {
                [$c, $m] = $this->current->runIn($workspace, function () use ($today): array {
                    $closed = $moved = 0;

                    foreach (Project::query()->whereNull('archived_at')->get() as $project) {
                        if ($project->cycle_weeks !== null) {
                            $closed += $this->cycles->advance($project, $today);
                            $this->cycles->current($project, $today);
                        }
                        if ($project->stale_after_days !== null) {
                            $moved += DecayStaleItems::decay($project, $this->agentRuns, $today);
                        }
                    }

                    return [$closed, $moved];
                });
                $closed += $c;
                $moved += $m;
            }

            $ms = (int) round((microtime(true) - $started) * 1000);
            $this->metrics->ingest($run, [[
                'at' => now()->getTimestamp(), 'kind' => 'flow', 'name' => 'midnight', 'count' => 1, 'sum_ms' => $ms, 'max_ms' => $ms,
                'histogram' => SimMetrics::histogramOf($ms), 'extra' => ['cycles_closed' => $closed, 'items_decayed' => $moved],
            ]], []);
        }
    }
}
