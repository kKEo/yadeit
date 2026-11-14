<?php

use App\Enums\WorkspaceRole;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-11-02 09:00:00');

    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
});

function decayItem(array $data = []): string
{
    return test()->postJson(test()->base.'/items', ['project' => 'WEB', 'title' => 'An item', ...$data])->assertCreated()->json('data.key');
}

function stateOf(string $key): string
{
    return test()->getJson(test()->base."/items/{$key}")->json('data.state.name');
}

it('does nothing in a project that has not asked for it', function () {
    $key = decayItem(['state' => 'In progress']);

    $this->travelTo('2027-06-01 09:00:00');
    $this->artisan('items:decay')->expectsOutput('Moved 0 stale item(s) to the backlog.')->assertSuccessful();

    expect(stateOf($key))->toBe('In progress');
});

it('returns untouched open items to the backlog and says why', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['stale_after_days' => 30])->assertOk()->assertJsonPath('data.stale_after_days', 30);

    $todo = decayItem(['assignee' => 'me']);
    $started = decayItem(['state' => 'In progress']);
    $backlog = decayItem(['state' => 'Backlog']);
    $done = decayItem(['state' => 'Done']);
    $waiting = decayItem(['state' => 'In progress']);
    $this->patchJson("{$this->base}/items/{$waiting}", ['needs_input' => true])->assertOk();

    // Still fresh after 29 days.
    $this->travelTo('2026-12-01 09:00:00');
    $this->artisan('items:decay')->expectsOutput('Moved 0 stale item(s) to the backlog.');

    // Someone touches one of them; the rest go stale.
    $this->postJson("{$this->base}/items/{$started}/comments", ['body' => 'Still on it'])->assertCreated();
    $this->patchJson("{$this->base}/items/{$started}", ['priority' => 'high'])->assertOk();

    $this->travelTo('2026-12-03 09:00:00');
    $this->artisan('items:decay')->expectsOutput('Moved 1 stale item(s) to the backlog.')->assertSuccessful();

    expect(stateOf($todo))->toBe('Backlog')
        ->and(stateOf($started))->toBe('In progress')
        ->and(stateOf($backlog))->toBe('Backlog')
        ->and(stateOf($done))->toBe('Done')
        ->and(stateOf($waiting))->toBe('In progress');

    $this->getJson("{$this->base}/items/{$todo}")->assertJsonPath('data.assignee.id', $this->user->id)->assertJsonPath('data.version', 2);
    $this->getJson("{$this->base}/items/{$todo}/activity")
        ->assertJsonPath('data.0.event', 'decayed')
        ->assertJsonPath('data.0.actor', null)
        ->assertJsonPath('data.0.changes.state', ['from' => 'Todo', 'to' => 'Backlog'])
        ->assertJsonPath('data.0.changes.idle_days', 30);

    // Moving it counted as a touch: it is not moved again the next night.
    $this->travelTo('2026-12-04 09:00:00');
    $this->artisan('items:decay')->expectsOutput('Moved 0 stale item(s) to the backlog.');
});

it('takes a stale item away from an agent that went quiet', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['stale_after_days' => 14])->assertOk();
    $key = decayItem();
    $agent = User::factory()->agent($this->workspace, $this->user)->create(['name' => 'Coder']);
    $token = $agent->createToken('t', ['read', 'write'])->plainTextToken;

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin')->withToken($token)->postJson("{$this->base}/items/{$key}/claim")->assertOk();

    $this->travelTo('2026-11-20 09:00:00');
    $this->artisan('items:decay')->expectsOutput('Moved 1 stale item(s) to the backlog.');

    asUser($this->user)->getJson("{$this->base}/items/{$key}")->assertJsonPath('data.state.name', 'Backlog')->assertJsonPath('data.delegate', null);
    $this->getJson("{$this->base}/items/{$key}/runs")->assertJsonPath('data.0.status', 'stopped');
    // It is free again for whoever asks for ready work once it is planned.
});

it('accepts only a sensible number of days', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['stale_after_days' => 3])->assertUnprocessable();
    $this->patchJson("{$this->base}/projects/WEB", ['stale_after_days' => null])->assertOk()->assertJsonPath('data.stale_after_days', null);
});
