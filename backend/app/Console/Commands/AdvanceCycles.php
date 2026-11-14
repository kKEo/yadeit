<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Workspace;
use App\Services\CycleScheduler;
use App\Support\CurrentWorkspace;
use Illuminate\Console\Command;

class AdvanceCycles extends Command
{
    protected $signature = 'cycles:advance';

    protected $description = 'Close cycles that have ended, open the next ones and move unfinished items along';

    public function handle(CurrentWorkspace $current, CycleScheduler $scheduler): int
    {
        $closed = 0;

        foreach (Workspace::query()->cursor() as $workspace) {
            $closed += $current->runIn($workspace, fn () => Project::query()
                ->whereNotNull('cycle_weeks')
                ->whereNull('archived_at')
                ->get()
                ->sum(function (Project $project) use ($scheduler): int {
                    $closedHere = $scheduler->advance($project);
                    $scheduler->current($project);

                    return $closedHere;
                }));
        }

        $this->info("Closed {$closed} cycle(s).");

        return self::SUCCESS;
    }
}
