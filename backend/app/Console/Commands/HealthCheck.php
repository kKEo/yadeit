<?php

namespace App\Console\Commands;

use App\Support\Health;
use Illuminate\Console\Command;

class HealthCheck extends Command
{
    protected $signature = 'app:health';

    protected $description = 'Report whether the database, queue, scheduler, storage and backups are working';

    public function handle(): int
    {
        $report = Health::report();

        foreach ($report['checks'] as $name => $check) {
            $this->line(sprintf('%-8s %-12s %s', strtoupper($check['status']), $name, $check['detail']));
        }

        return $report['status'] === 'failing' ? self::FAILURE : self::SUCCESS;
    }
}
