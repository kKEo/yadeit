<?php

namespace App\Simulator;

use App\Simulator\Models\SimFinding;
use App\Simulator\Models\SimMetricBucket;
use App\Simulator\Models\SimRun;
use Illuminate\Support\Collection;

/**
 * What a run showed: totals, every endpoint, action and flow with its
 * latencies, a timeline for charts, and the verdict against the run's
 * thresholds. Built from the stored buckets, and kept on the run when it
 * ends so it outlives the purge.
 */
class SimReport
{
    public const THRESHOLDS = [
        // 95 of 100 requests answer within this many milliseconds.
        'p95_ms' => 1000,
        // At most this share of requests may fail (server errors and unexpected refusals).
        'error_rate' => 0.01,
        // At least this share of flows must complete.
        'flow_success' => 0.99,
    ];

    /** Actions starting later than this, 95 times out of 100, mean the runner could not keep up and the numbers flatter the server. */
    private const SATURATED_LAG_MS = 2000;

    /** @return array<string, mixed> */
    public function build(SimRun $run): array
    {
        $rows = SimMetricBucket::query()->where('run_id', $run->id)->orderBy('bucket_at')->get();

        if ($rows->isEmpty() && $run->summary !== null) {
            return $run->summary;
        }

        $endpoints = $this->table($rows->where('kind', 'endpoint'));
        $flows = $this->table($rows->where('kind', 'flow'));
        $requests = $this->sum($rows->where('kind', 'endpoint'));
        $flowTotal = $this->sum($rows->where('kind', 'flow'));
        $lag = $this->sum($rows->where('kind', 'runner'));
        $seconds = max(SimMetrics::STEP_SECONDS, $rows->where('kind', 'endpoint')->pluck('bucket_at')->unique()->count() * SimMetrics::STEP_SECONDS);
        $thresholds = array_replace(self::THRESHOLDS, (array) data_get($run->config, 'thresholds', []));

        $totals = [
            'requests' => $requests['count'],
            'failed_requests' => $requests['errors'],
            'error_rate' => $requests['count'] > 0 ? round($requests['errors'] / $requests['count'], 4) : 0.0,
            'requests_per_second' => round($requests['count'] / $seconds, 1),
            'p50_ms' => $requests['p50_ms'], 'p95_ms' => $requests['p95_ms'], 'p99_ms' => $requests['p99_ms'], 'max_ms' => $requests['max_ms'],
            'actions' => $this->sum($rows->where('kind', 'action'))['count'],
            'failed_actions' => $this->sum($rows->where('kind', 'action'))['errors'],
            'flows' => $flowTotal['count'],
            'failed_flows' => $flowTotal['errors'],
            'findings' => SimFinding::query()->where('run_id', $run->id)->count(),
            'runner_lag_p95_ms' => $lag['p95_ms'],
            'oldest_job_seconds' => (int) $rows->where('kind', 'server')->max(fn (SimMetricBucket $r) => $r->extra['oldest_job_seconds'] ?? 0),
        ];

        $reasons = [];

        if ($totals['requests'] === 0) {
            $reasons[] = 'No request was measured.';
        }
        if ($totals['p95_ms'] > $thresholds['p95_ms']) {
            $slowest = collect($endpoints)->sortByDesc('p95_ms')->first();
            $reasons[] = "95% of requests took up to {$totals['p95_ms']} ms (limit {$thresholds['p95_ms']} ms); slowest: {$slowest['name']} at {$slowest['p95_ms']} ms.";
        }
        if ($totals['error_rate'] > $thresholds['error_rate']) {
            $reasons[] = sprintf('%.1f%% of requests failed (limit %.1f%%).', $totals['error_rate'] * 100, $thresholds['error_rate'] * 100);
        }
        if ($flowTotal['count'] > 0 && 1 - $flowTotal['errors'] / $flowTotal['count'] < $thresholds['flow_success']) {
            $broken = collect($flows)->where('errors', '>', 0)->pluck('name')->implode(', ');
            $reasons[] = "{$flowTotal['errors']} of {$flowTotal['count']} flows failed: {$broken}.";
        }

        return [
            'run_id' => $run->id,
            'status' => $run->status->value,
            'verdict' => $reasons === [] ? 'pass' : 'fail',
            'reasons' => $reasons,
            // Not a failure of the server, but a reason to distrust good numbers.
            'runner_saturated' => $totals['runner_lag_p95_ms'] > self::SATURATED_LAG_MS,
            'thresholds' => $thresholds,
            'totals' => $totals,
            'endpoints' => $endpoints,
            'actions' => $this->table($rows->where('kind', 'action')),
            'flows' => $flows,
            'timeline' => $this->timeline($rows),
        ];
    }

    /** Builds the report and keeps it on the run. */
    public function save(SimRun $run): SimRun
    {
        $report = $this->build($run);
        $run->update(['summary' => $report, 'verdict' => $report['verdict']]);

        return $run;
    }

    /**
     * How each endpoint changed against an earlier run: the difference in the 95% latency.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $baseline
     * @return list<array{name: string, p95_ms: int, baseline_p95_ms: int|null, change_ms: int|null, change_percent: float|null}>
     */
    public function compare(array $report, array $baseline): array
    {
        $before = collect((array) ($baseline['endpoints'] ?? []))->keyBy('name');

        return collect((array) ($report['endpoints'] ?? []))->map(function (array $row) use ($before): array {
            $old = $before[$row['name']]['p95_ms'] ?? null;

            return [
                'name' => $row['name'],
                'p95_ms' => $row['p95_ms'],
                'baseline_p95_ms' => $old,
                'change_ms' => $old === null ? null : $row['p95_ms'] - $old,
                'change_percent' => $old === null || $old === 0 ? null : round(($row['p95_ms'] - $old) / $old * 100, 1),
            ];
        })->sortByDesc(fn (array $row) => $row['change_ms'] ?? PHP_INT_MIN)->values()->all();
    }

    /**
     * One row per name, slowest first.
     *
     * @param  Collection<int, SimMetricBucket>  $rows
     * @return list<array<string, mixed>>
     */
    private function table(Collection $rows): array
    {
        return $rows->groupBy('name')->map(fn (Collection $group, string $name) => ['name' => $name] + $this->sum($group))
            ->sortByDesc('p95_ms')->values()->all();
    }

    /**
     * @param  Collection<int, SimMetricBucket>  $rows
     * @return array{count: int, errors: int, mean_ms: int, p50_ms: int, p95_ms: int, p99_ms: int, max_ms: int, server_ms: int|null, db_ms: int|null, queries: float|null}
     */
    private function sum(Collection $rows): array
    {
        $count = (int) $rows->sum('count');
        $max = (int) $rows->max('max_ms');
        $histogram = $rows->reduce(fn (array $carry, SimMetricBucket $row) => SimMetrics::add($carry, (array) $row->histogram), []);
        $extra = $rows->reduce(fn (array $carry, SimMetricBucket $row) => SimMetrics::add($carry, (array) $row->extra), []);
        $timed = (float) ($extra['timed'] ?? 0);

        return [
            'count' => $count,
            'errors' => (int) $rows->sum('errors'),
            'mean_ms' => $count > 0 ? (int) round($rows->sum('sum_ms') / $count) : 0,
            'p50_ms' => SimMetrics::percentile($histogram, 0.50, $max),
            'p95_ms' => SimMetrics::percentile($histogram, 0.95, $max),
            'p99_ms' => SimMetrics::percentile($histogram, 0.99, $max),
            'max_ms' => $max,
            // What the server itself reported for these requests: time in the application, time in the database, queries per request.
            'server_ms' => $timed > 0 ? (int) round(($extra['server_ms'] ?? 0) / $timed) : null,
            'db_ms' => $timed > 0 ? (int) round(($extra['db_ms'] ?? 0) / $timed) : null,
            'queries' => $timed > 0 ? round(($extra['queries'] ?? 0) / $timed, 1) : null,
        ];
    }

    /**
     * One point per ten seconds.
     *
     * @param  Collection<int, SimMetricBucket>  $rows
     * @return list<array<string, mixed>>
     */
    private function timeline(Collection $rows): array
    {
        return $rows->groupBy(fn (SimMetricBucket $row) => $row->bucket_at->getTimestamp())->map(function (Collection $step, int $at): array {
            $requests = $this->sum($step->where('kind', 'endpoint'));
            $server = (array) ($step->firstWhere('kind', 'server')->extra ?? []);

            return [
                'at' => $at,
                'requests_per_second' => round($requests['count'] / SimMetrics::STEP_SECONDS, 1),
                'errors' => $requests['errors'],
                'p50_ms' => $requests['p50_ms'],
                'p95_ms' => $requests['p95_ms'],
                'p99_ms' => $requests['p99_ms'],
                'failed_flows' => (int) $step->where('kind', 'flow')->sum('errors'),
                'runner_lag_ms' => $this->sum($step->where('kind', 'runner'))['p95_ms'],
                'jobs_waiting' => (int) ($server['jobs_waiting'] ?? 0),
                'oldest_job_seconds' => (int) ($server['oldest_job_seconds'] ?? 0),
            ];
        })->values()->all();
    }
}
