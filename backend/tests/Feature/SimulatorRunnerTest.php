<?php

use App\Models\User;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimTeam;
use App\Simulator\SimRuns;
use App\Simulator\Simulator;

beforeEach(function () {
    config(['simulator.enabled' => true]);
    [$this->run, $this->token] = app(SimRuns::class)->start(['teams' => 3, 'size_min' => 2, 'size_max' => 4, 'real_signup_share' => 0.34, 'speed' => 5]);
});

it('starts a run within the caps and refuses a second one', function () {
    expect($this->run->status->value)->toBe('running')->and($this->run->speed)->toBe(5)
        ->and(SimTeam::query()->where('onboarding', 'real')->count())->toBe(1)
        ->and(SimTeam::query()->where('status', 'ready')->count())->toBe(2);

    expect(fn () => app(SimRuns::class)->start([]))->toThrow(Exception::class);
    expect(app(SimRuns::class)->normalise(['teams' => 9999, 'speed' => 7, 'size_min' => 40])['teams'])->toBe(50)
        ->and(app(SimRuns::class)->normalise(['speed' => 7])['speed'])->toBe(1)
        ->and(app(SimRuns::class)->normalise(['size_min' => 40])['size_max'])->toBe(10);
});

it('serves runners only with the token of the run', function () {
    $this->postJson('/api/v1/sim/runner/register', ['name' => 'r1'])->assertUnauthorized();
    $this->withToken("{$this->run->id}|wrong")->postJson('/api/v1/sim/runner/register', ['name' => 'r1'])->assertUnauthorized();

    config(['simulator.enabled' => false]);
    $this->withToken($this->token)->postJson('/api/v1/sim/runner/register', ['name' => 'r1'])->assertNotFound();
});

it('hands a runner its teams with what the members need to sign in', function () {
    $id = $this->withToken($this->token)->postJson('/api/v1/sim/runner/register', ['name' => 'r1'])
        ->assertCreated()->assertJsonPath('data.run.speed', 5)->assertJsonPath('data.origin', 'http://localhost:5173')
        ->assertJsonPath('data.pack.name', 'Default')->json('data.runner_id');

    $teams = $this->postJson('/api/v1/sim/runner/poll', ['runner_id' => $id])->assertOk()->assertJsonCount(3, 'data.teams')->json('data.teams');
    $ready = collect($teams)->firstWhere('onboarding', 'fast');
    $member = $ready['members'][0];

    expect($ready['workspace'])->toBeString()->and($member['header'])->toStartWith("{$this->run->id}.m{$member['id']}.");

    // Another runner gets nothing while the first one is alive, and everything once it has gone silent.
    $second = $this->postJson('/api/v1/sim/runner/register', ['name' => 'r2'])->json('data.runner_id');
    $this->postJson('/api/v1/sim/runner/poll', ['runner_id' => $second])->assertJsonCount(0, 'data.teams');
    $this->travel(30)->seconds();
    $this->postJson('/api/v1/sim/runner/poll', ['runner_id' => $second])->assertJsonCount(3, 'data.teams');
});

it('lets a team sign up, follow its mail and report itself ready', function () {
    $id = $this->withToken($this->token)->postJson('/api/v1/sim/runner/register', ['name' => 'r1'])->json('data.runner_id');
    $team = collect($this->postJson('/api/v1/sim/runner/poll', ['runner_id' => $id])->json('data.teams'))->firstWhere('onboarding', 'real');
    [$lead, $second] = $team['members'];
    $password = ['password' => $lead['password'], 'password_confirmation' => $lead['password']];

    $this->withoutToken()->withHeaders([Simulator::HEADER => $lead['header']])
        ->postJson('/api/v1/auth/register', ['name' => $lead['name'], 'email' => $lead['email']] + $password)->assertCreated();

    $link = $this->withToken($this->token)->getJson('/api/v1/sim/runner/mailbox?kind=VerifyEmail&email='.urlencode($lead['email']))->assertOk()->json('data.links.0');
    parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
    $this->withoutToken()->getJson("/api/v1/auth/verify-email/{$q['id']}/{$q['hash']}?expires={$q['expires']}&signature={$q['signature']}")->assertOk();

    // The test keeps the signed-in account in memory; read it again now that it is confirmed.
    auth()->forgetGuards();
    $slug = $this->postJson('/api/v1/workspaces', ['name' => $team['name']])->assertCreated()->json('data.slug');
    $this->postJson("/api/v1/workspaces/{$slug}/invitations", ['email' => $second['email'], 'role' => $second['role']])->assertCreated();

    $invite = $this->withToken($this->token)->getJson('/api/v1/sim/runner/mailbox?kind=WorkspaceInvitation&email='.urlencode($second['email']))->json('data.links.0');
    expect($invite)->toContain('/invitations/');

    $this->postJson("/api/v1/sim/runner/teams/{$team['id']}/ready")->assertOk()->assertJsonPath('data.status', 'ready');
    expect(SimTeam::query()->findOrFail($team['id'])->workspace_id)->not->toBeNull()
        ->and(User::query()->where('email', $lead['email'])->value('sim_run_id'))->toBe($this->run->id);
});

it('ends the run when its time is up', function () {
    $id = $this->withToken($this->token)->postJson('/api/v1/sim/runner/register', ['name' => 'r1'])->json('data.runner_id');
    $this->travel(11)->minutes();

    $this->postJson('/api/v1/sim/runner/poll', ['runner_id' => $id])->assertOk()->assertJsonPath('data.run.status', 'finished');
    expect(SimRun::query()->findOrFail($this->run->id)->finished_at)->not->toBeNull();
});

it('starts and stops from the command line', function () {
    $this->artisan('sim:stop --purge')->assertSuccessful();
    expect(User::query()->whereNotNull('sim_run_id')->count())->toBe(0);

    $this->artisan('sim:start --teams=1 --size=2 --real=0 --volume=empty')->assertSuccessful();
    expect(app(SimRuns::class)->active())->not->toBeNull();
});
