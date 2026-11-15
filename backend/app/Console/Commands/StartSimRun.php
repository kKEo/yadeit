<?php

namespace App\Console\Commands;

use App\Simulator\SimRuns;
use Illuminate\Console\Command;

class StartSimRun extends Command
{
    protected $signature = 'sim:start
        {--teams=3 : Number of teams}
        {--size=2-6 : Team size, one number or a range}
        {--speed=10 : 1, 2, 5, 10, 50 or 100 times organic speed}
        {--minutes=10 : How long the run lasts}
        {--day=workday : realistic, workday or continuous}
        {--real=0.34 : Share of teams that sign up through the API}
        {--volume=small : Starting backlog: empty, small, medium or large}
        {--ramp=30 : Seconds over which the teams join}
        {--seed=1 : Same seed, same teams and behaviour}
        {--token-only : Print only the runner token}';

    protected $description = 'Start a Traffic lab run and print the token its runners sign in with';

    public function handle(SimRuns $runs): int
    {
        [$min, $max] = array_pad(array_map('intval', explode('-', (string) $this->option('size'), 2)), 2, null);

        [$run, $token] = $runs->start([
            'teams' => (int) $this->option('teams'),
            'size_min' => $min,
            'size_max' => $max ?? $min,
            'speed' => (int) $this->option('speed'),
            'duration_minutes' => (int) $this->option('minutes'),
            'day_mode' => (string) $this->option('day'),
            'real_signup_share' => (float) $this->option('real'),
            'volume' => (string) $this->option('volume'),
            'ramp_up_seconds' => (int) $this->option('ramp'),
            'seed' => (int) $this->option('seed'),
        ], name: 'Command line run');

        if ($this->option('token-only')) {
            $this->line($token);

            return self::SUCCESS;
        }

        $this->info("Run {$run->id} started: {$run->teams()->count()} teams, {$run->members()->count()} members, {$run->speed}x.");
        $this->line("Runner token (shown once): {$token}");

        return self::SUCCESS;
    }
}
