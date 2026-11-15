<?php

namespace App\Console\Commands;

use App\Simulator\Models\SimRun;
use App\Simulator\SimReport;
use Illuminate\Console\Command;

class ReportSimRun extends Command
{
    protected $signature = 'sim:report {run? : Run id; the latest run when left out} {--json : Print the whole report as JSON} {--baseline= : Compare with this earlier run}';

    protected $description = 'Show what a Traffic lab run measured; exits with 1 when the run failed its thresholds';

    public function handle(SimReport $reports): int
    {
        $run = $this->argument('run') !== null ? SimRun::query()->find((int) $this->argument('run')) : SimRun::query()->orderByDesc('id')->first();

        if ($run === null) {
            $this->error('There is no run.');

            return self::FAILURE;
        }

        $report = $reports->build($run);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['verdict'] === 'pass' ? self::SUCCESS : self::FAILURE;
        }

        $t = $report['totals'];
        $this->line("Run {$run->id}: ".strtoupper($report['verdict']).($report['runner_saturated'] ? ' (the runner could not keep up: numbers may flatter the server)' : ''));
        $this->line("Requests {$t['requests']} at {$t['requests_per_second']}/s, failed {$t['failed_requests']}; p50 {$t['p50_ms']} ms, p95 {$t['p95_ms']} ms, p99 {$t['p99_ms']} ms, max {$t['max_ms']} ms");
        $this->line("Actions {$t['actions']}, failed {$t['failed_actions']}; flows {$t['flows']}, failed {$t['failed_flows']}; findings {$t['findings']}; oldest waiting job {$t['oldest_job_seconds']} s");

        foreach ($report['reasons'] as $reason) {
            $this->warn("  - {$reason}");
        }

        $this->table(['Endpoint', 'Count', 'Failed', 'p50', 'p95', 'p99', 'Server', 'DB', 'Queries'], array_map(
            fn (array $e) => [$e['name'], $e['count'], $e['errors'], $e['p50_ms'], $e['p95_ms'], $e['p99_ms'], $e['server_ms'] ?? '-', $e['db_ms'] ?? '-', $e['queries'] ?? '-'],
            array_slice($report['endpoints'], 0, 12),
        ));
        $this->table(['Flow', 'Count', 'Failed', 'p50', 'p95'], array_map(fn (array $f) => [$f['name'], $f['count'], $f['errors'], $f['p50_ms'], $f['p95_ms']], $report['flows']));

        if ($this->option('baseline') !== null && ($baseline = SimRun::query()->find((int) $this->option('baseline'))) !== null) {
            $this->line("Against run {$baseline->id}:");
            $this->table(['Endpoint', 'p95', 'Before', 'Change ms', 'Change %'], array_map('array_values', array_slice($reports->compare($report, $reports->build($baseline)), 0, 12)));
        }

        return $report['verdict'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
