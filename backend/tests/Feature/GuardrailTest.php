<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    [$this->workspace, $this->owner] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->owner);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $this->key = $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'An item'])->json('data.key');
    $this->itemUrl = "{$this->base}/items/{$this->key}";

    $this->agent = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Coder']);
    $this->token = $this->agent->createToken('laptop', ['read', 'write'])->plainTextToken;
});

function guardAgent(?string $token = null): mixed
{
    app('auth')->forgetGuards();
    test()->flushSession();

    return test()->withoutHeader('Origin')->withToken($token ?? test()->token);
}

it('does not let an agent close or cancel an item', function () {
    guardAgent()->postJson("{$this->itemUrl}/claim")->assertOk();

    foreach (['Done', 'Cancelled'] as $state) {
        $this->patchJson($this->itemUrl, ['state' => $state])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'agents_cannot_close');
    }

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Born done', 'state' => 'Done'])->assertForbidden();

    // Every open state stays available, and review is the way to finish.
    $this->patchJson($this->itemUrl, ['state' => 'Backlog'])->assertOk();
    $this->postJson("{$this->itemUrl}/submit-for-review", ['summary' => 'Ready'])->assertOk()->assertJsonPath('data.state.name', 'In review');

    // The person closes it.
    asUser($this->owner)->patchJson($this->itemUrl, ['state' => 'Done'])->assertOk()->assertJsonPath('data.state.name', 'Done');

    // Other edits to an already closed item do not count as closing it.
    guardAgent()->patchJson($this->itemUrl, ['title' => 'Renamed'])->assertOk();
});

it('does not let an agent choose who is responsible or hand work to another agent', function () {
    $other = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Rival']);

    guardAgent()->patchJson($this->itemUrl, ['assignee' => $this->owner->id])->assertForbidden()->assertJsonPath('error.code', 'agents_cannot_assign');
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'x', 'assignee' => $this->owner->id])->assertForbidden();
    $this->patchJson($this->itemUrl, ['delegate' => 'Rival'])->assertForbidden()->assertJsonPath('error.code', 'agents_cannot_assign');

    // Taking it itself and giving its own back are fine.
    $this->patchJson($this->itemUrl, ['delegate' => 'me'])->assertOk()->assertJsonPath('data.delegate.name', 'Coder');
    $this->patchJson($this->itemUrl, ['delegate' => null])->assertOk();

    // It cannot take work away from another agent either.
    asUser($this->owner)->patchJson($this->itemUrl, ['delegate' => $other->id])->assertOk();
    guardAgent()->patchJson($this->itemUrl, ['delegate' => null])->assertForbidden();
});

it('does not let an agent delete or reshape the workspace', function (string $method, string $path) {
    $attachment = null;
    $path = str_replace(['{item}', '{agent}'], [$this->key, (string) $this->agent->id], $path);

    guardAgent()->json($method, "{$this->base}/{$path}", ['name' => 'x', 'key' => 'NEW'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'not_available_to_agents');
})->with([
    'delete an item' => ['DELETE', 'items/{item}'],
    'create a project' => ['POST', 'projects'],
    'change a project' => ['PATCH', 'projects/WEB'],
    'delete a project' => ['DELETE', 'projects/WEB'],
    'delete an attachment' => ['DELETE', 'items/{item}/attachments/1'],
    'delete a label' => ['DELETE', 'projects/WEB/labels/1'],
    'remove itself from the workspace' => ['DELETE', 'members/{agent}'],
]);

it('still lets a person do all of that', function () {
    $this->patchJson("{$this->base}/projects/WEB", ['name' => 'Site'])->assertOk();
    $this->deleteJson($this->itemUrl)->assertNoContent();
});

it('refuses unknown fields and names the accepted ones', function () {
    $this->patchJson($this->itemUrl, ['state_id' => 4, 'assignee_id' => 2])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.fields.state_id.0', fn (string $m) => str_starts_with($m, 'Unknown field. This endpoint accepts: title, expected_version, description, state, priority'))
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['assignee_id']]]]);

    $this->postJson("{$this->base}/views", ['name' => 'Mine', 'shared' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.shared.0', fn (string $m) => str_contains($m, 'is_shared'));

    // Nothing was changed by the refused request.
    $this->getJson($this->itemUrl)->assertJsonPath('data.version', 1);

    // Query parameters of read requests are not affected.
    $this->getJson("{$this->base}/items?utm_source=x")->assertOk();
});

it('records which token a change came through', function () {
    guardAgent()->postJson("{$this->itemUrl}/claim")->assertOk();
    asUser($this->owner)->patchJson($this->itemUrl, ['title' => 'Renamed'])->assertOk();

    $this->getJson("{$this->itemUrl}/activity")
        ->assertJsonPath('data.0.actor.type', 'human')
        ->assertJsonPath('data.0.token', null)
        ->assertJsonPath('data.1.event', 'claimed')
        ->assertJsonPath('data.1.actor.type', 'agent')
        ->assertJsonPath('data.1.token', 'laptop');

    // The name stays in the history after the token is revoked.
    $this->agent->tokens()->delete();
    $this->getJson("{$this->itemUrl}/activity")->assertJsonPath('data.1.token', 'laptop');
});

it('limits each token separately', function () {
    RateLimiter::for('api', fn ($request) => Limit::perMinute(2)->by(
        $request->bearerToken() !== null ? 'token:'.$request->user()->currentAccessToken()->id : $request->user()->id
    ));
    $second = $this->agent->createToken('server', ['read'])->plainTextToken;

    guardAgent()->getJson("{$this->base}/items")->assertOk();
    $this->getJson("{$this->base}/items")->assertOk();
    $this->getJson("{$this->base}/items")
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');

    // The agent's other token has its own allowance.
    guardAgent($second)->getJson("{$this->base}/items")->assertOk();
});
