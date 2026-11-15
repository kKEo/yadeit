<?php

use App\Ai\AiProvider;
use App\Models\User;
use App\Simulator\Models\SimContentPack;
use App\Simulator\Models\SimMember;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimStoryline;
use App\Simulator\Models\SimTeam;
use App\Simulator\SimRuns;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAiProvider;

beforeEach(function () {
    config(['simulator.enabled' => true, 'ai.api_key' => 'instance-test-key-0000000000']);
    $this->fake = new FakeAiProvider;
    $this->app->instance(AiProvider::class, $this->fake);
    $this->start = function (array $ai = [], int $size = 3): array {
        [$run, $token] = app(SimRuns::class)->start(['teams' => 2, 'size_min' => $size, 'size_max' => $size, 'real_signup_share' => 0, 'volume' => 'empty', 'ai' => $ai]);

        return [$run, $token, SimTeam::query()->where('run_id', $run->id)->orderBy('id')->get()];
    };
});

it('makes no call to a model while the scenario has AI off', function () {
    [$run, $token, $teams] = ($this->start)();

    $this->withToken($token)->postJson('/api/v1/sim/runner/storyline', ['team_id' => $teams[0]->id, 'sim_day' => 0])->assertOk()->assertJsonPath('data.steps', []);
    $member = SimMember::query()->where('run_id', $run->id)->firstOrFail();
    $this->postJson('/api/v1/sim/runner/decide', ['member_id' => $member->id, 'options' => ['openItems']])->assertUnprocessable();

    expect($this->fake->prompts)->toBe([])->and(SimMember::query()->where('kind', 'ai')->count())->toBe(0)->and($run->refresh()->ai_tokens_used)->toBe(0);
});

it('has the director write a team\'s day once, keeps it, and plays only what can be played', function () {
    [$run, $token, $teams] = ($this->start)(['director' => true]);
    $this->fake->answer = ['steps' => [
        ['member' => 1, 'action' => 'createItem', 'text' => 'Fix the export of invoices'],
        ['member' => 2, 'action' => 'openItem', 'text' => ''],
        ['member' => 9, 'action' => 'comment', 'text' => 'Nobody is number nine'],
        ['member' => 2, 'action' => 'deleteEverything', 'text' => ''],
    ]];

    $ask = fn () => $this->withToken($token)->postJson('/api/v1/sim/runner/storyline', ['team_id' => $teams[0]->id, 'sim_day' => 0])->assertOk()->json('data.steps');
    $first = $ask();

    expect($first)->toBe([
        ['member' => 1, 'action' => 'createItem', 'text' => 'Fix the export of invoices'],
        ['member' => 2, 'action' => 'openItem', 'text' => null],
    ])->and($ask())->toBe($first)
        ->and($this->fake->prompts)->toHaveCount(1)
        ->and($this->fake->prompts[0]->model)->toBe('claude-haiku-5-5')
        ->and($this->fake->prompts[0]->content)->toContain('Monday')->toContain('role owner')
        ->and($run->refresh()->ai_tokens_used)->toBe(1500);
});

it('replays the storylines of an earlier run without asking a model', function () {
    [$first, $token, $teams] = ($this->start)(['director' => true]);
    $this->fake->answer = ['steps' => [['member' => 1, 'action' => 'search', 'text' => '']]];
    $this->withToken($token)->postJson('/api/v1/sim/runner/storyline', ['team_id' => $teams[1]->id, 'sim_day' => 2])->assertOk();
    app(SimRuns::class)->stop($first, 'done');

    [$second, $token2, $teams2] = ($this->start)(['replay_run_id' => $first->id]);
    $this->fake->prompts = [];

    $this->withToken($token2)->postJson('/api/v1/sim/runner/storyline', ['team_id' => $teams2[1]->id, 'sim_day' => 2])->assertOk()
        ->assertJsonPath('data.steps', [['member' => 1, 'action' => 'search', 'text' => null]]);
    // The other team had no story that day, and none is invented.
    $this->postJson('/api/v1/sim/runner/storyline', ['team_id' => $teams2[0]->id, 'sim_day' => 2])->assertOk()->assertJsonPath('data.steps', []);

    expect($this->fake->prompts)->toBe([])->and(SimStoryline::query()->where('run_id', $second->id)->count())->toBe(1);
});

it('lets a few explorers choose their own next step, from what they may do', function () {
    [$run, $token] = ($this->start)(['explorers_per_team' => 3], 4);
    $explorers = SimMember::query()->where('run_id', $run->id)->where('kind', 'ai')->get();

    // Three per team were asked for; the run as a whole gets three, and never the lead.
    expect($explorers)->toHaveCount(3)->and($explorers->pluck('role')->all())->not->toContain('owner');

    $this->fake->answer = ['action' => 'comment', 'reason' => 'The item I opened has an open question.', 'stuck' => 'Search finds nothing for a title I can see in the list.'];
    $this->withToken($token)->postJson('/api/v1/sim/runner/decide', [
        'member_id' => $explorers[0]->id, 'options' => ['openItems', 'comment', 'launchRockets'], 'history' => ['search: ok, 0 results'], 'seen' => '12 items in the list',
    ])->assertOk()->assertJsonPath('data.action', 'comment')->assertJsonPath('data.stuck', 'Search finds nothing for a title I can see in the list.');

    expect($this->fake->prompts[0]->schema['properties']['action']['enum'])->toBe(['openItems', 'comment'])
        ->and($this->fake->prompts[0]->content)->toContain('search: ok, 0 results');

    // An answer outside the list is not followed.
    $this->fake->answer = ['action' => 'settings', 'reason' => '', 'stuck' => ''];
    $this->postJson('/api/v1/sim/runner/decide', ['member_id' => $explorers[0]->id, 'options' => ['openItems', 'comment']])
        ->assertOk()->assertJsonPath('data.action', 'openItems')->assertJsonPath('data.stuck', null);

    $scripted = SimMember::query()->where('run_id', $run->id)->where('kind', 'scripted')->firstOrFail();
    $this->postJson('/api/v1/sim/runner/decide', ['member_id' => $scripted->id, 'options' => ['openItems']])->assertUnprocessable();
});

it('stops asking, and stops the run, once the budget is spent', function () {
    [$run, $token] = ($this->start)(['explorers_per_team' => 1, 'token_budget' => 2000]);
    $explorer = SimMember::query()->where('run_id', $run->id)->where('kind', 'ai')->firstOrFail();
    $this->fake->answer = ['action' => 'openItems', 'reason' => '', 'stuck' => ''];
    $ask = fn () => $this->withToken($token)->postJson('/api/v1/sim/runner/decide', ['member_id' => $explorer->id, 'options' => ['openItems']]);

    // Each answer of the stand-in costs 1500 tokens: the second call passes the budget and ends the run.
    $ask()->assertOk();
    $ask()->assertOk();
    $ask()->assertStatus(429);

    expect($this->fake->prompts)->toHaveCount(2)
        ->and(SimRun::query()->findOrFail($run->id)->stop_reason)->toContain('budget');
});

it('says so when the installation has no provider key', function () {
    config(['ai.api_key' => null]);
    [, $token, $teams] = ($this->start)(['director' => true]);

    $this->withToken($token)->postJson('/api/v1/sim/runner/storyline', ['team_id' => $teams[0]->id, 'sim_day' => 0])->assertStatus(503);
    expect($this->fake->prompts)->toBe([]);
});

it('has a model write a content pack, which a run can then use', function () {
    $this->fake->answer = [
        'people' => ['Ada Example', 'Bo Sample', 'Cy Trial'], 'teams' => ['Harbour Clinic IT'],
        'projects' => [['key' => 'apt!', 'name' => 'Appointments', 'description' => 'Booking for patients.', 'items' => [['title' => 'Send reminders by text message', 'description' => 'A day before.', 'kind' => 'feature', 'priority' => 'high']]]],
        'comments' => ['Checked with the front desk.'], 'pages' => [['title' => 'On-call', 'body' => 'Who to ring.']], 'feedback' => [['title' => 'Cannot cancel online', 'body' => 'I had to phone.']],
    ];
    $operator = User::factory()->create(['is_operator' => true]);

    $id = $this->actingAs($operator)->postJson('/api/v1/admin/sim/packs', ['name' => 'Clinic', 'theme' => 'The IT team of a small clinic building a booking system'])
        ->assertCreated()->assertJsonPath('data.source', 'ai')->json('data.id');

    expect($this->fake->prompts[0]->model)->toBe(config('ai.model'))
        ->and(SimContentPack::query()->findOrFail($id)->content['projects'][0]['key'])->toBe('APT');
    $this->getJson('/api/v1/admin/sim/packs')->assertJsonCount(2, 'data');

    $run = $this->postJson('/api/v1/admin/sim/runs', ['config' => ['teams' => 1, 'size_min' => 2, 'size_max' => 2, 'real_signup_share' => 0, 'pack_id' => $id]])->assertCreated()->json('data.id');
    expect(DB::table('projects')->where('key', 'APT')->count())->toBe(1)
        ->and(SimMember::query()->where('run_id', $run)->pluck('name')->diff(['Ada Example', 'Bo Sample', 'Cy Trial'])->all())->toBe([]);

    $this->fake->answer = ['people' => [], 'teams' => [], 'projects' => [], 'comments' => [], 'pages' => [], 'feedback' => []];
    $this->postJson('/api/v1/admin/sim/packs', ['name' => 'Empty', 'theme' => 'Something that comes back empty'])->assertStatus(502);
    $this->deleteJson("/api/v1/admin/sim/packs/{$id}")->assertNoContent();
});
