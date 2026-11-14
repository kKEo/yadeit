<?php

use App\Enums\WorkspaceRole;
use App\Models\AgentRun;
use App\Models\InboxItem;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->owner] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->owner);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $this->key = $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'An item'])->json('data.key');
    $this->itemUrl = "{$this->base}/items/{$this->key}";

    $this->agent = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Coder']);
    $this->token = $this->agent->createToken('test', ['read', 'write'])->plainTextToken;
});

function runAgent(?string $token = null): mixed
{
    app('auth')->forgetGuards();
    test()->flushSession();

    return test()->withoutHeader('Origin')->withToken($token ?? test()->token);
}

function runPerson(User $user): mixed
{
    test()->flushSession();

    return test()->withoutToken()->withHeader('Origin', 'http://localhost:5173')->actingAs($user);
}

it('opens a run when an agent takes an item and logs its steps in order', function () {
    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();

    $this->postJson("{$this->itemUrl}/log", ['type' => 'thought', 'body' => 'Plan: 1. read 2. fix'])->assertCreated();
    $this->postJson("{$this->itemUrl}/log", ['type' => 'action', 'body' => 'Opened pull request #12'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.entries.*.type', ['thought', 'action']);

    runPerson($this->owner)->getJson("{$this->itemUrl}/runs")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.agent.name', 'Coder')
        ->assertJsonPath('data.0.stale', false)
        ->assertJsonPath('data.0.entries.1.body', 'Opened pull request #12');
});

it('follows a run through a question, an answer and the result', function () {
    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();
    $this->postJson("{$this->itemUrl}/ask", ['question' => 'Which database?'])->assertOk();

    $this->getJson("{$this->itemUrl}/runs")
        ->assertJsonPath('data.0.status', 'awaiting_input')
        ->assertJsonPath('data.0.entries.0.type', 'question');

    runPerson($this->owner)->postJson("{$this->itemUrl}/comments", ['body' => 'PostgreSQL'])->assertCreated();
    $this->getJson("{$this->itemUrl}/runs")->assertJsonPath('data.0.status', 'active');

    runAgent()->postJson("{$this->itemUrl}/submit-for-review", ['summary' => 'Done in PR #12'])->assertOk();

    $this->getJson("{$this->itemUrl}/runs")
        ->assertJsonPath('data.0.status', 'completed')
        ->assertJsonPath('data.0.entries.*.type', ['question', 'response'])
        ->assertJsonPath('data.0.entries.1.body', 'Done in PR #12');

    expect(AgentRun::withoutGlobalScopes()->first()->ended_at)->not->toBeNull();
});

it('starts a new run when the agent picks the item up again', function () {
    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();
    $this->postJson("{$this->itemUrl}/submit-for-review")->assertOk();

    // Sent back by the reviewer: the agent logs again and gets a fresh run.
    $this->postJson("{$this->itemUrl}/log", ['type' => 'thought', 'body' => 'Addressing review comments'])->assertCreated();

    $this->getJson("{$this->itemUrl}/runs")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.1.status', 'completed');
});

it('ends the run as failed on an error and tells the owner', function () {
    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();

    $this->postJson("{$this->itemUrl}/log", ['type' => 'error', 'body' => 'The test suite cannot be installed.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'failed');

    expect(InboxItem::withoutGlobalScopes()->where('user_id', $this->owner->id)->where('event', 'run_failed')->count())->toBe(1);
});

it('stops the run when the agent gives the item back or loses it', function () {
    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();
    $this->deleteJson("{$this->itemUrl}/claim")->assertOk();
    $this->getJson("{$this->itemUrl}/runs")->assertJsonPath('data.0.status', 'stopped');

    $this->postJson("{$this->itemUrl}/claim")->assertOk();
    runPerson($this->owner)->patchJson($this->itemUrl, ['delegate' => null])->assertOk();

    $this->getJson("{$this->itemUrl}/runs")->assertJsonPath('data.*.status', ['stopped', 'stopped']);
});

it('marks a silent active run as stale', function () {
    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();
    $this->travel(31)->minutes();

    $this->getJson("{$this->itemUrl}/runs")->assertJsonPath('data.0.status', 'active')->assertJsonPath('data.0.stale', true);

    $this->postJson("{$this->itemUrl}/log", ['type' => 'action', 'body' => 'Still here'])->assertCreated()->assertJsonPath('data.stale', false);
});

it('only lets the agent holding the item write to its log', function () {
    $rival = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Rival']);
    $rivalToken = $rival->createToken('test', ['read', 'write'])->plainTextToken;

    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();

    runAgent($rivalToken)->postJson("{$this->itemUrl}/log", ['type' => 'action', 'body' => 'Sneaking in'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_claimed_by_you');

    runPerson($this->owner)->postJson("{$this->itemUrl}/log", ['type' => 'action', 'body' => 'I am a person'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'agents_only');
});

it('takes questions and results only through ask and submit-for-review', function () {
    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();

    $this->postJson("{$this->itemUrl}/log", ['type' => 'question', 'body' => 'Hm?'])->assertUnprocessable();
    $this->postJson("{$this->itemUrl}/log", ['type' => 'response', 'body' => 'Done'])->assertUnprocessable();
    $this->postJson("{$this->itemUrl}/log", ['type' => 'action'])->assertUnprocessable();
});

it('creates no run for a person who claims or asks', function () {
    $this->postJson("{$this->itemUrl}/claim")->assertOk();
    $this->postJson("{$this->itemUrl}/ask", ['question' => 'Anyone?'])->assertOk();
    $this->postJson("{$this->itemUrl}/submit-for-review", ['summary' => 'Ready'])->assertOk();

    $this->getJson("{$this->itemUrl}/runs")->assertOk()->assertJsonCount(0, 'data');
});

it('keeps runs of another workspace out of reach', function () {
    runAgent()->postJson("{$this->itemUrl}/claim")->assertOk();
    [$other, $stranger] = workspaceWith();

    runPerson($stranger)->getJson("{$this->itemUrl}/runs")->assertNotFound();
    $this->getJson("/api/v1/workspaces/{$other->slug}/items/{$this->key}/runs")->assertNotFound();
});
