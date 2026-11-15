<?php

namespace App\Console\Commands;

use App\Simulator\Actions\PurgeSimRun;
use App\Simulator\Models\SimRun;
use Illuminate\Console\Command;

class PruneSimRuns extends Command
{
    protected $signature = 'sim:prune';

    protected $description = 'Remove Traffic lab runs that ended longer ago than the retention period, with everything they created';

    public function handle(PurgeSimRun $purge): int
    {
        $before = now()->subDays((int) config('simulator.retention_days'));
        $runs = SimRun::query()->whereNotNull('finished_at')->where('finished_at', '<', $before)->get();

        $runs->each(fn (SimRun $run) => $purge->handle($run, forget: true));
        $this->info("Removed {$runs->count()} run(s).");

        return self::SUCCESS;
    }
}
