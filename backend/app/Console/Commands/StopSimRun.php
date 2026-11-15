<?php

namespace App\Console\Commands;

use App\Simulator\Actions\PurgeSimRun;
use App\Simulator\Models\SimRun;
use App\Simulator\SimRuns;
use Illuminate\Console\Command;

class StopSimRun extends Command
{
    protected $signature = 'sim:stop {run? : Run id; the active run when left out} {--purge : Also remove everything the run created}';

    protected $description = 'Stop a Traffic lab run';

    public function handle(SimRuns $runs, PurgeSimRun $purge): int
    {
        $run = $this->argument('run') !== null ? SimRun::query()->find((int) $this->argument('run')) : ($runs->active() ?? SimRun::query()->orderByDesc('id')->first());

        if ($run === null) {
            $this->warn('There is no run.');

            return self::SUCCESS;
        }

        $runs->stop($run, 'Stopped from the command line.');

        if ($this->option('purge')) {
            $purge->handle($run);
        }

        $this->info("Run {$run->id} is {$run->refresh()->status->value}".($this->option('purge') ? ' and purged.' : '.'));

        return self::SUCCESS;
    }
}
