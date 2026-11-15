<?php

use App\Models\User;
use App\Models\Workspace;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimTeam;
use App\Simulator\SimMetrics;

beforeEach(function () {
    config(['simulator.enabled' => true]);
    $this->operator = User::factory()->create(['is_operator' => true]);
    $this->actingAs($this->operator);
    $this->small = ['teams' => 2, 'size_min' => 2, 'size_max' => 2, 'real_signup_share' => 0, 'volume' => 'empty'];
});

it('is only for operators, and only while switched on', function () {
    [, $owner] = workspaceWith();
    $this->actingAs($owner)->getJson('/api/v1/admin/sim/runs')->assertNotFound();
    $this->postJson('/api/v1/admin/sim/runs', [])->assertNotFound();

    config(['simulator.enabled' => false]);
    $this->actingAs($this->operator)->getJson('/api/v1/admin/sim/runs')->assertNotFound();
});

it('keeps scenarios, holding their numbers to the caps', function () {
    $id = $this->postJson('/api/v1/admin/sim/scenarios', ['name' => 'Busy day', 'config' => ['teams' => 500, 'speed' => 50, 'disabled_actions' => ['flowDoc']]])
        ->assertCreated()->assertJsonPath('data.config.teams', 50)->assertJsonPath('data.config.speed', 50)
        ->assertJsonPath('data.config.day_mode', 'workday')->assertJsonPath('data.config.disabled_actions', ['flowDoc'])->json('data.id');

    $this->postJson('/api/v1/admin/sim/scenarios', ['name' => 'Bad', 'config' => ['speed' => 3]])->assertUnprocessable();
    $this->patchJson("/api/v1/admin/sim/scenarios/{$id}", ['name' => 'Quiet day', 'config' => ['teams' => 4, 'thresholds' => ['p95_ms' => 300]]])
        ->assertOk()->assertJsonPath('data.name', 'Quiet day')->assertJsonPath('data.config.thresholds.p95_ms', 300);
    $this->getJson('/api/v1/admin/sim/scenarios')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/admin/sim/scenarios/{$id}")->assertNoContent();
});

it('lists the content packs and shows one', function () {
    $this->getJson('/api/v1/admin/sim/packs')->assertOk()->assertJsonPath('data.0.source', 'builtin');
    $this->getJson('/api/v1/admin/sim/packs/0')->assertOk()->assertJsonPath('data.projects.0.key', 'WEB');
});

it('starts a run from a scenario, steers it, stops it and removes it', function () {
    $scenario = $this->postJson('/api/v1/admin/sim/scenarios', ['name' => 'Two teams', 'config' => $this->small])->json('data.id');

    $start = $this->postJson('/api/v1/admin/sim/runs', ['scenario_id' => $scenario, 'config' => ['speed' => 5]])
        ->assertCreated()->assertJsonPath('data.name', 'Two teams')->assertJsonPath('data.speed', 5)->assertJsonPath('data.active', true);
    $id = $start->json('data.id');
    expect($start->json('data.runner_token'))->toStartWith("{$id}|");

    $this->postJson('/api/v1/admin/sim/runs', ['config' => $this->small])->assertStatus(409);
    $this->getJson('/api/v1/admin/sim')->assertJsonPath('data.active_run_id', $id);

    $this->patchJson("/api/v1/admin/sim/runs/{$id}", ['speed' => 100])->assertOk()->assertJsonPath('data.speed', 100);
    $this->patchJson("/api/v1/admin/sim/runs/{$id}", ['speed' => 3])->assertUnprocessable();
    $this->patchJson("/api/v1/admin/sim/runs/{$id}", ['paused' => true])->assertOk()->assertJsonPath('data.status', 'paused');
    $this->patchJson("/api/v1/admin/sim/runs/{$id}", ['paused' => false, 'add_teams' => 2])->assertOk()->assertJsonPath('data.status', 'running');
    expect(SimTeam::query()->where('run_id', $id)->count())->toBe(4);

    $this->patchJson("/api/v1/admin/sim/runs/{$id}", ['remove_teams' => 10])->assertOk();
    expect(SimTeam::query()->where('run_id', $id)->where('status', '!=', 'removed')->count())->toBe(1);

    $this->deleteJson("/api/v1/admin/sim/runs/{$id}")->assertStatus(409);
    $this->postJson("/api/v1/admin/sim/runs/{$id}/stop")->assertOk()->assertJsonPath('data.status', 'finished')->assertJsonPath('data.purged', false);
    $this->patchJson("/api/v1/admin/sim/runs/{$id}", ['speed' => 10])->assertStatus(409);

    $this->deleteJson("/api/v1/admin/sim/runs/{$id}")->assertNoContent();
    expect(Workspace::query()->whereNotNull('sim_run_id')->count())->toBe(0);
    $this->getJson('/api/v1/admin/sim/runs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.purged', true);

    $this->deleteJson("/api/v1/admin/sim/runs/{$id}?forget=true")->assertNoContent();
    expect(SimRun::query()->count())->toBe(0);
});

it('shows a run with its teams, what the members are doing, its runners and its report', function () {
    $start = $this->postJson('/api/v1/admin/sim/runs', ['config' => $this->small])->assertCreated();
    $id = $start->json('data.id');
    $token = $start->json('data.runner_token');

    $runner = $this->withToken($token)->postJson('/api/v1/sim/runner/register', ['name' => 'r1', 'host' => 'box'])->json('data.runner_id');
    $member = $this->postJson('/api/v1/sim/runner/poll', ['runner_id' => $runner])->json('data.teams.0.members.0.id');
    $this->postJson('/api/v1/sim/runner/poll', ['runner_id' => $runner, 'lag_ms' => 40, 'activity' => [$member => 'openItems']])->assertOk();
    $histogram = array_fill(0, count(SimMetrics::BOUNDS) + 1, 0);
    $histogram[5] = 10;
    $this->postJson('/api/v1/sim/runner/metrics', [
        'buckets' => [['at' => now()->getTimestamp(), 'kind' => 'endpoint', 'name' => 'GET /api/v1/me', 'count' => 10, 'histogram' => $histogram, 'max_ms' => 9]],
        'findings' => [['flow' => 'isolation', 'step' => 'another workspace', 'message' => 'Could read it']],
    ])->assertOk();

    $this->withoutToken()->actingAs($this->operator)->getJson("/api/v1/admin/sim/runs/{$id}")->assertOk()
        ->assertJsonCount(2, 'data.teams')
        ->assertJsonPath('data.teams.0.members.0.action', 'openItems')
        ->assertJsonPath('data.teams.0.members.0.persona', 'lead')
        ->assertJsonPath('data.runners.0.alive', true)->assertJsonPath('data.runners.0.lag_ms', 40)
        ->assertJsonPath('data.report.totals.requests', 10)
        ->assertJsonPath('data.findings.0.flow', 'isolation');
});

it('lets a standing runner play whichever run is going on', function () {
    config(['simulator.runner_token' => 'standing-secret']);

    $this->withToken('standby|standing-secret')->postJson('/api/v1/sim/runner/register', ['name' => 's'])->assertStatus(409);
    $this->withToken('standby|wrong')->postJson('/api/v1/sim/runner/register', ['name' => 's'])->assertUnauthorized();

    $id = $this->withoutToken()->actingAs($this->operator)->postJson('/api/v1/admin/sim/runs', ['config' => $this->small])->json('data.id');
    $this->withToken('standby|standing-secret')->postJson('/api/v1/sim/runner/register', ['name' => 's'])->assertCreated()->assertJsonPath('data.run.id', $id);
});

it('compares a run with an earlier one', function () {
    $first = $this->postJson('/api/v1/admin/sim/runs', ['config' => $this->small])->json('data.id');
    $this->postJson("/api/v1/admin/sim/runs/{$first}/stop")->assertOk();
    $second = $this->postJson('/api/v1/admin/sim/runs', ['config' => $this->small])->json('data.id');

    $this->getJson("/api/v1/admin/sim/runs/{$second}/compare/{$first}")->assertOk()->assertJsonPath('data.endpoints', []);
});
