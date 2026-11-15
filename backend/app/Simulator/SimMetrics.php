<?php

namespace App\Simulator;

use App\Simulator\Models\SimFinding;
use App\Simulator\Models\SimMetricBucket;
use App\Simulator\Models\SimRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stores what runners measured. Each row sums ten seconds of one endpoint,
 * action or flow; the latency histogram has fixed bounds, so the rows of
 * several runners, and of several ten-second steps, simply add up.
 */
class SimMetrics
{
    /** Upper bounds of the latency buckets, in milliseconds. A last bucket holds everything slower. */
    public const BOUNDS = [1, 2, 3, 5, 7, 10, 15, 20, 30, 50, 70, 100, 150, 200, 300, 500, 700, 1000, 1500, 2000, 3000, 5000, 7000, 10000, 15000, 30000];

    public const STEP_SECONDS = 10;

    public const KINDS = ['endpoint', 'action', 'flow', 'runner'];

    /**
     * @param  list<array<string, mixed>>  $buckets
     * @param  list<array<string, mixed>>  $findings
     */
    public function ingest(SimRun $run, array $buckets, array $findings): void
    {
        DB::transaction(function () use ($run, $buckets, $findings): void {
            foreach ($buckets as $bucket) {
                $at = Carbon::createFromTimestamp(intdiv((int) $bucket['at'], self::STEP_SECONDS) * self::STEP_SECONDS);
                $row = SimMetricBucket::query()->where('run_id', $run->id)->where('bucket_at', $at)
                    ->where('kind', $bucket['kind'])->where('name', $bucket['name'])->lockForUpdate()->first()
                    ?? new SimMetricBucket(['run_id' => $run->id, 'bucket_at' => $at, 'kind' => $bucket['kind'], 'name' => $bucket['name']]);

                $row->count += (int) $bucket['count'];
                $row->errors += (int) ($bucket['errors'] ?? 0);
                $row->sum_ms += (int) round((float) ($bucket['sum_ms'] ?? 0));
                $row->max_ms = max((int) $row->max_ms, (int) round((float) ($bucket['max_ms'] ?? 0)));
                $row->histogram = self::add((array) $row->histogram, array_map('intval', (array) ($bucket['histogram'] ?? [])));
                $row->extra = self::add((array) $row->extra, array_map('floatval', (array) ($bucket['extra'] ?? [])));
                $row->save();
            }

            foreach ($findings as $finding) {
                SimFinding::create([
                    'run_id' => $run->id,
                    'team_id' => $finding['team_id'] ?? null,
                    'member_id' => $finding['member_id'] ?? null,
                    'flow' => $finding['flow'],
                    'step' => $finding['step'] ?? null,
                    'severity' => $finding['severity'] ?? 'error',
                    'message' => mb_substr((string) $finding['message'], 0, 500),
                    'details' => $finding['details'] ?? null,
                    'created_at' => now(),
                ]);
            }

            $this->sampleServer($run);
        });
    }

    /** What the installation looks like from inside, once per step: waiting work and open sessions. */
    private function sampleServer(SimRun $run): void
    {
        $at = Carbon::createFromTimestamp(intdiv(now()->getTimestamp(), self::STEP_SECONDS) * self::STEP_SECONDS);

        if (SimMetricBucket::query()->where('run_id', $run->id)->where('bucket_at', $at)->where('kind', 'server')->exists()) {
            return;
        }

        $oldest = DB::table('jobs')->min('available_at');

        SimMetricBucket::create([
            'run_id' => $run->id, 'bucket_at' => $at, 'kind' => 'server', 'name' => 'sample', 'count' => 1,
            'extra' => [
                'jobs_waiting' => DB::table('jobs')->count(),
                'oldest_job_seconds' => $oldest === null ? 0 : max(0, now()->getTimestamp() - (int) $oldest),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'sessions' => DB::table('sessions')->where('last_activity', '>=', now()->subMinutes(5)->getTimestamp())->count(),
            ],
        ]);
    }

    /**
     * A histogram holding one measurement.
     *
     * @return list<int>
     */
    public static function histogramOf(int $ms): array
    {
        $histogram = array_fill(0, count(self::BOUNDS) + 1, 0);
        $slot = count(self::BOUNDS);

        foreach (self::BOUNDS as $index => $bound) {
            if ($ms <= $bound) {
                $slot = $index;
                break;
            }
        }

        $histogram[$slot] = 1;

        return $histogram;
    }

    /**
     * Adds two lists or maps of numbers, position by position.
     *
     * @param  array<array-key, int|float>  $a
     * @param  array<array-key, int|float>  $b
     * @return array<array-key, int|float>
     */
    public static function add(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] = ($a[$key] ?? 0) + $value;
        }

        return $a;
    }

    /**
     * The latency below which the given share of requests stayed: the upper
     * bound of the bucket that share falls into.
     *
     * @param  array<array-key, int|float>  $histogram
     */
    public static function percentile(array $histogram, float $share, int $max): int
    {
        $total = array_sum($histogram);

        if ($total <= 0) {
            return 0;
        }

        $seen = 0;

        foreach (array_values($histogram) as $index => $count) {
            $seen += $count;

            if ($seen >= $share * $total) {
                return min($max, self::BOUNDS[$index] ?? $max);
            }
        }

        return $max;
    }
}
