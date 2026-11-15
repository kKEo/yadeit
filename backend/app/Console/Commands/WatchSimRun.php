<?php

namespace App\Console\Commands;

use App\Simulator\SimRuns;
use Illuminate\Console\Command;

class WatchSimRun extends Command
{
    protected $signature = 'sim:watch';

    protected $description = 'Stop the Traffic lab run when a stop rule applies (time up, too many errors, runners gone, AI budget spent)';

    public function handle(SimRuns $runs): int
    {
        if (($run = $runs->active()) !== null) {
            $runs->enforceLimits($run);
        }

        return self::SUCCESS;
    }
}
