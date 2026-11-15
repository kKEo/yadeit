<?php

use App\Simulator\Actions\PurgeSimRun;
use App\Simulator\Models\SimFinding;
use App\Simulator\Models\SimMetricBucket;
use App\Simulator\Models\SimRun;
use App\Simulator\SimMetrics;
use App\Simulator\SimReport;
use App\Simulator\SimRuns;

/** A histogram with $count requests in the bucket whose upper bound is $ms. */
function latencies(int $ms, int $count): array
{
    $histogram = array_fill(0, count(SimMetrics::BOUNDS) + 1, 0);
    $histogram[array_search($ms, SimMetrics::BOUNDS, true)] = $count;

    return $histogram;
}

function bucket(string $kind, string $name, int $count, int $ms, int $errors = 0, ?int $at = null): array
{
    return ['at' => $at ?? now()->getTimestamp(), 'kind' => $kind, 'name' => $name, 'count' => $count, 'errors' => $errors,
        'sum_ms' => $count * $ms, 'max_ms' => $ms, 'histogram' => latencies($ms, $count),
        'extra' => $kind === 'endpoint' ? ['timed' => $count, 'server_ms' => $count * $ms / 2, 'db_ms' => $count * 3, 'queries' => $count * 7] : []];
}

beforeEach(function () {
    config(['simulator.enabled' => true]);
    [$this->run, $this->token] = app(SimRuns::class)->start(['teams' => 1, 'size_min' => 1, 'size_max' => 1, 'real_signup_share' => 0, 'volume' => 'empty']);
    $this->runner = $this->withToken($this->token)->postJson('/api/v1/sim/runner/register', ['name' => 'r1'])
        ->assertCreated()->assertJsonPath('data.histogram_bounds', SimMetrics::BOUNDS)->json('data.runner_id');
    $this->report = fn (array $buckets, array $findings = []) => $this->postJson('/api/v1/sim/runner/metrics', ['buckets' => $buckets, 'findings' => $findings]);
});

it('adds up what several runners report for the same ten seconds', function () {
    ($this->report)([bucket('endpoint', 'GET /api/v1/me', 90, 20)])->assertOk();
    ($this->report)([bucket('endpoint', 'GET /api/v1/me', 10, 500, errors: 2)])->assertOk();

    $row = SimMetricBucket::query()->where('kind', 'endpoint')->sole();
    expect($row->count)->toBe(100)->and($row->errors)->toBe(2)->and($row->max_ms)->toBe(500)
        ->and(array_sum($row->histogram))->toBe(100)
        ->and(SimMetricBucket::query()->where('kind', 'server')->count())->toBe(1);

    $report = app(SimReport::class)->build($this->run);
    $me = $report['endpoints'][0];
    // 90 fast and 10 slow: the median is fast, the 95th of a hundred is slow.
    expect($me['p50_ms'])->toBe(20)->and($me['p95_ms'])->toBe(500)->and($me['queries'])->toBe(7.0)->and($me['db_ms'])->toBe(3)
        ->and($report['totals']['error_rate'])->toBe(0.02)
        ->and($report['timeline'])->toHaveCount(1);
});

it('finds percentiles from a histogram', function () {
    expect(SimMetrics::percentile(latencies(100, 10), 0.95, 80))->toBe(80)
        ->and(SimMetrics::percentile(SimMetrics::add(latencies(10, 99), latencies(3000, 1)), 0.99, 2900))->toBe(10)
        ->and(SimMetrics::percentile(SimMetrics::add(latencies(10, 98), latencies(3000, 2)), 0.99, 2900))->toBe(2900)
        ->and(SimMetrics::percentile([], 0.5, 0))->toBe(0);
});

it('passes a run that stayed inside its thresholds', function () {
    ($this->report)([bucket('endpoint', 'GET /api/v1/me', 500, 50), bucket('action', 'openItems', 100, 200), bucket('flow', 'itemLifecycle', 20, 700)])->assertOk();

    $report = app(SimReport::class)->build($this->run);
    expect($report['verdict'])->toBe('pass')->and($report['reasons'])->toBe([])->and($report['runner_saturated'])->toBeFalse();
});

it('fails the run for a slow endpoint and names it', function () {
    ($this->report)([bucket('endpoint', 'GET /api/v1/me', 400, 50), bucket('endpoint', 'GET /api/v1/workspaces/{workspace}/search', 100, 3000)])->assertOk();

    $report = app(SimReport::class)->build($this->run);
    expect($report['verdict'])->toBe('fail')->and($report['reasons'][0])->toContain('/search')->toContain('3000 ms')
        ->and($report['endpoints'][0]['name'])->toContain('/search');

    $this->artisan('sim:report')->expectsOutputToContain('FAIL')->assertFailed();
});

it('fails the run for a broken flow and keeps what the check saw', function () {
    ($this->report)(
        [bucket('endpoint', 'GET /api/v1/me', 500, 50), bucket('flow', 'createToSearch', 10, 300, errors: 4), bucket('flow', 'itemLifecycle', 10, 300)],
        [['flow' => 'createToSearch', 'step' => 'find', 'message' => 'The new item was not found within 10 s', 'details' => ['q' => 'zx81']]],
    )->assertOk();

    $report = app(SimReport::class)->build($this->run);
    expect($report['verdict'])->toBe('fail')->and($report['reasons'][0])->toContain('createToSearch')->not->toContain('itemLifecycle')
        ->and($report['totals']['findings'])->toBe(1)
        ->and(SimFinding::query()->sole()->details)->toBe(['q' => 'zx81']);
});

it('warns that good numbers cannot be trusted when the runner fell behind', function () {
    ($this->report)([bucket('endpoint', 'GET /api/v1/me', 500, 50), bucket('runner', 'lag', 100, 5000)])->assertOk();

    expect(app(SimReport::class)->build($this->run)['runner_saturated'])->toBeTrue();
});

it('respects thresholds set for the run', function () {
    $this->run->update(['config' => ['thresholds' => ['p95_ms' => 10]] + $this->run->config]);
    ($this->report)([bucket('endpoint', 'GET /api/v1/me', 500, 50)])->assertOk();

    expect(app(SimReport::class)->build($this->run)['verdict'])->toBe('fail');
});

it('stops by itself when most requests fail', function () {
    ($this->report)([bucket('endpoint', 'GET /api/v1/me', 100, 50, errors: 80)])->assertOk()->assertJsonPath('data.status', 'aborted');

    $run = SimRun::query()->findOrFail($this->run->id);
    expect($run->stop_reason)->toContain('failed')->and($run->verdict)->toBe('fail');
});

it('stops by itself when its runners are gone', function () {
    $this->travel(3)->minutes();
    $this->artisan('sim:watch')->assertSuccessful();

    expect(SimRun::query()->findOrFail($this->run->id)->status->value)->toBe('failed');
});

it('stops when the AI budget is spent', function () {
    $this->run->update(['config' => ['ai' => ['director' => true, 'token_budget' => 1000]] + $this->run->config, 'ai_tokens_used' => 1000]);
    $this->postJson('/api/v1/sim/runner/poll', ['runner_id' => $this->runner])->assertJsonPath('data.run.status', 'finished');
});

it('keeps the report on the run after a purge, and compares two runs', function () {
    ($this->report)([bucket('endpoint', 'GET /api/v1/me', 500, 50)])->assertOk();
    app(SimRuns::class)->stop($this->run, 'done');
    app(PurgeSimRun::class)->handle($this->run);

    $first = app(SimReport::class)->build($this->run->refresh());
    expect(SimMetricBucket::query()->count())->toBe(0)->and($first['totals']['requests'])->toBe(500)->and($this->run->verdict)->toBe('pass');

    [$second] = app(SimRuns::class)->start(['teams' => 1, 'size_min' => 1, 'size_max' => 1, 'real_signup_share' => 0, 'volume' => 'empty']);
    app(SimMetrics::class)->ingest($second, [bucket('endpoint', 'GET /api/v1/me', 500, 200)], []);

    $changes = app(SimReport::class)->compare(app(SimReport::class)->build($second), $first);
    expect($changes[0])->toMatchArray(['name' => 'GET /api/v1/me', 'p95_ms' => 200, 'baseline_p95_ms' => 50, 'change_ms' => 150, 'change_percent' => 300.0]);
});
