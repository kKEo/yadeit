<?php

use App\Enums\WorkspaceRole;
use App\Models\Cycle;

beforeEach(function () {
    // A Wednesday, so "start of the week" is visibly different from today.
    $this->travelTo('2026-11-18 09:00:00');

    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
});

function cycleItem(array $data = []): string
{
    return test()->postJson(test()->base.'/items', ['project' => 'WEB', 'title' => 'An item', ...$data])->assertCreated()->json('data.key');
}

it('starts the first cycle on the Monday of the week cycles are switched on', function () {
    $this->getJson("{$this->base}/projects/WEB/cycles")->assertOk()->assertJsonCount(0, 'data');

    $this->patchJson("{$this->base}/projects/WEB", ['cycle_weeks' => 2])->assertOk()->assertJsonPath('data.cycle_weeks', 2);

    $this->getJson("{$this->base}/projects/WEB/cycles")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Cycle 1')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.starts_on', '2026-11-16')
        ->assertJsonPath('data.0.ends_on', '2026-11-29');
});

it('puts work into the current cycle when it starts, and on request', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['cycle_weeks' => 1])->assertOk();

    $todo = cycleItem();
    $planned = cycleItem(['cycle' => 'current']);
    $started = cycleItem(['state' => 'In progress']);

    $this->getJson("{$this->base}/items/{$todo}")->assertJsonPath('data.cycle', null);
    $this->getJson("{$this->base}/items/{$planned}")->assertJsonPath('data.cycle.number', 1);
    $this->getJson("{$this->base}/items/{$started}")->assertJsonPath('data.cycle.name', 'Cycle 1');

    $this->patchJson("{$this->base}/items/{$todo}", ['state' => 'In progress'])->assertOk()->assertJsonPath('data.cycle.number', 1);
    $this->patchJson("{$this->base}/items/{$todo}", ['cycle' => null])->assertOk()->assertJsonPath('data.cycle', null);

    // Claiming starts the work, so it joins the cycle too.
    $claimed = cycleItem();
    $this->postJson("{$this->base}/items/{$claimed}/claim")->assertOk()->assertJsonPath('data.cycle.number', 1);

    $this->getJson("{$this->base}/items?project=WEB&cycle=current")->assertJsonCount(3, 'data');
    $this->getJson("{$this->base}/items?project=WEB&cycle=none")->assertJsonCount(1, 'data');
});

it('closes an ended cycle, opens the next and rolls unfinished work over', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['cycle_weeks' => 1])->assertOk();

    $done = cycleItem(['cycle' => 'current', 'state' => 'Done']);
    $open = cycleItem(['cycle' => 'current', 'state' => 'In progress']);
    $todo = cycleItem(['cycle' => 'current']);

    // The cycle ends on Sunday the 22nd; nothing happens before that.
    $this->travelTo('2026-11-22 23:00:00');
    $this->artisan('cycles:advance')->expectsOutput('Closed 0 cycle(s).')->assertSuccessful();

    $this->travelTo('2026-11-23 00:10:00');
    $this->artisan('cycles:advance')->expectsOutput('Closed 1 cycle(s).')->assertSuccessful();

    $this->getJson("{$this->base}/projects/WEB/cycles")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.number', 2)
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.starts_on', '2026-11-23')
        ->assertJsonPath('data.0.items', ['total' => 2, 'completed' => 0, 'started' => 1])
        ->assertJsonPath('data.1.status', 'completed')
        ->assertJsonPath('data.1.items', ['total' => 1, 'completed' => 1, 'started' => 0]);

    $this->getJson("{$this->base}/items/{$done}")->assertJsonPath('data.cycle.number', 1);
    $this->getJson("{$this->base}/items/{$open}")->assertJsonPath('data.cycle.number', 2);
    $this->getJson("{$this->base}/items/{$todo}")->assertJsonPath('data.cycle.number', 2);

    $this->getJson("{$this->base}/items/{$open}/activity")
        ->assertJsonPath('data.0.event', 'rolled_over')
        ->assertJsonPath('data.0.actor', null)
        ->assertJsonPath('data.0.changes.cycle', ['from' => 'Cycle 1', 'to' => 'Cycle 2']);

    // Running it again the same day changes nothing.
    $this->artisan('cycles:advance')->expectsOutput('Closed 0 cycle(s).');
    expect(Cycle::withoutGlobalScopes()->count())->toBe(2);
});

it('catches up when the schedule did not run for a while', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['cycle_weeks' => 1])->assertOk();
    $open = cycleItem(['cycle' => 'current']);

    $this->travelTo('2026-12-09 12:00:00');

    // Even without the command, asking for the cycles brings them up to date.
    $this->getJson("{$this->base}/projects/WEB/cycles")
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.starts_on', '2026-12-07')
        ->assertJsonPath('data.0.status', 'active');

    $this->getJson("{$this->base}/items/{$open}")->assertJsonPath('data.cycle.number', 4);
});

it('keeps existing cycles when they are switched off and continues when switched on again', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['cycle_weeks' => 1])->assertOk();
    $key = cycleItem(['cycle' => 'current']);

    $this->patchJson("{$this->base}/projects/WEB", ['cycle_weeks' => null])->assertOk()->assertJsonPath('data.cycle_weeks', null);

    $this->travelTo('2026-12-01 12:00:00');
    $this->artisan('cycles:advance')->expectsOutput('Closed 0 cycle(s).');
    $this->getJson("{$this->base}/items/{$key}")->assertJsonPath('data.cycle.number', 1);
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'x', 'state' => 'In progress'])->assertCreated()->assertJsonPath('data.cycle', null);
});

it('explains a wrong cycle reference', function () {
    $key = cycleItem();

    $this->patchJson("{$this->base}/items/{$key}", ['cycle' => 3])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.cycle.0', 'Project WEB does not use cycles.');

    // "current" in a project without cycles simply means none.
    $this->patchJson("{$this->base}/items/{$key}", ['cycle' => 'current'])->assertOk()->assertJsonPath('data.cycle', null);

    $this->patchJson("{$this->base}/projects/WEB", ['cycle_weeks' => 9])->assertUnprocessable();
});

it('does not touch cycles of another workspace', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['cycle_weeks' => 1])->assertOk();
    [$other, $stranger] = workspaceWith();
    $otherBase = "/api/v1/workspaces/{$other->slug}";
    $this->actingAs($stranger)->postJson("{$otherBase}/projects", ['name' => 'Other', 'key' => 'WEB'])->assertCreated();

    $this->getJson("{$otherBase}/projects/WEB/cycles")->assertJsonCount(0, 'data');
    $this->postJson("{$otherBase}/items", ['project' => 'WEB', 'title' => 'x', 'cycle' => 1])->assertUnprocessable();
});
