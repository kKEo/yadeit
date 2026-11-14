<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    [$this->workspace, $this->owner] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->owner);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $this->postJson("{$this->base}/projects", ['name' => 'Mobile', 'key' => 'MOB'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Web item'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'MOB', 'title' => 'Mobile item'])->assertCreated();
});

/** Creates an agent as the signed-in person and returns its id. */
function makeAgent(string $name = 'Coder'): int
{
    return test()->postJson(test()->base.'/agents', ['name' => $name])->assertCreated()->json('data.id');
}

/** Creates a token for the agent and returns the secret. */
function makeToken(int $agentId, array $data = []): string
{
    return test()->postJson(test()->base."/agents/{$agentId}/tokens", ['name' => 'laptop', 'access' => 'write', ...$data])
        ->assertCreated()->json('data.token');
}

/** Switches from the signed-in person to a bearer token, as an agent would call. */
function withAgentToken(string $token): mixed
{
    // A real agent is not a browser: no signed-in person, no session, no web origin.
    app('auth')->forgetGuards();
    test()->flushSession();

    return test()->withoutHeader('Origin')->withToken($token);
}

/** Switches back to a person using the web app. */
function asPerson(User $user): mixed
{
    test()->flushSession();

    return test()->withoutToken()->withHeader('Origin', 'http://localhost:5173')->actingAs($user);
}

it('creates an agent as a member owned by its creator', function () {
    $this->postJson("{$this->base}/agents", ['name' => 'Coder', 'description' => 'Writes code'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Coder')
        ->assertJsonPath('data.owner.id', $this->owner->id)
        ->assertJsonPath('data.token_count', 0);

    $this->getJson("{$this->base}/members")
        ->assertJsonFragment(['name' => 'Coder', 'type' => 'agent', 'role' => 'member', 'email' => null]);
});

it('does not let guests create agents', function () {
    $guest = addMember($this->workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)->postJson("{$this->base}/agents", ['name' => 'Coder'])->assertForbidden();
});

it('shows the token secret once and never again', function () {
    $agent = makeAgent();

    $created = $this->postJson("{$this->base}/agents/{$agent}/tokens", ['name' => 'laptop', 'access' => 'read', 'expires_in_days' => 30])
        ->assertCreated()
        ->assertJsonPath('data.access', 'read')
        ->assertJsonPath('data.projects', null)
        ->json('data');

    expect($created['token'])->toBeString()->and($created['expires_at'])->not->toBeNull();

    $this->getJson("{$this->base}/agents/{$agent}/tokens")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.token');
});

it('lets an agent work through its token and attributes the work to it', function () {
    $agent = makeAgent();
    $token = makeToken($agent);

    withAgentToken($token)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.type', 'agent')
        ->assertJsonPath('data.email', null);

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'From the agent'])->assertCreated();
    $this->postJson("{$this->base}/items/WEB-2/comments", ['body' => 'On it'])
        ->assertCreated()
        ->assertJsonPath('data.author.id', $agent);

    expect(PersonalAccessToken::first()->last_used_at)->not->toBeNull();
});

it('keeps a read-only token from changing anything', function () {
    $token = makeToken(makeAgent(), ['access' => 'read']);

    withAgentToken($token)->getJson("{$this->base}/items")->assertOk()->assertJsonCount(2, 'data');

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Nope'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_read_only');
    $this->patchJson("{$this->base}/items/WEB-1", ['title' => 'Nope'])->assertForbidden();
    $this->deleteJson("{$this->base}/items/WEB-1")->assertForbidden();
});

it('hides everything outside the projects a token is limited to', function () {
    $agent = makeAgent();

    $this->postJson("{$this->base}/agents/{$agent}/tokens", ['name' => 'web only', 'access' => 'write', 'projects' => ['web']])
        ->assertCreated()
        ->assertJsonPath('data.projects', ['WEB']);

    $token = makeToken($agent, ['projects' => ['WEB']]);

    withAgentToken($token)->getJson("{$this->base}/items")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', 'WEB-1');

    $this->getJson("{$this->base}/projects")->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/items/MOB-1")->assertNotFound();
    $this->getJson("{$this->base}/projects/MOB/states")->assertNotFound();
    $this->patchJson("{$this->base}/items/MOB-1", ['title' => 'Nope'])->assertNotFound();
    $this->postJson("{$this->base}/items/MOB-1/comments", ['body' => 'Nope'])->assertNotFound();
    $this->postJson("{$this->base}/items", ['project' => 'MOB', 'title' => 'Nope'])->assertUnprocessable();
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Child', 'parent' => 'MOB-1'])->assertUnprocessable();
    $this->postJson("{$this->base}/projects", ['name' => 'New', 'key' => 'NEW'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_project_scope');

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fine'])->assertCreated();
});

it('rejects a token for an unknown project', function () {
    $this->postJson("{$this->base}/agents/".makeAgent().'/tokens', ['name' => 'x', 'access' => 'read', 'projects' => ['NOPE']])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.projects.0', 'Unknown project: NOPE. Pass project keys of this workspace.');
});

it('stops working when the token is revoked or expired', function () {
    $agent = makeAgent();
    $token = makeToken($agent);
    $tokenId = PersonalAccessToken::first()->id;

    $this->deleteJson("{$this->base}/agents/{$agent}/tokens/{$tokenId}")->assertNoContent();
    withAgentToken($token)->getJson("{$this->base}/items")->assertUnauthorized();

    asPerson($this->owner);
    $expiring = makeToken($agent, ['expires_in_days' => 1]);
    $this->travel(2)->days();
    withAgentToken($expiring)->getJson("{$this->base}/items")->assertUnauthorized();
});

it('keeps agents inside their workspace and away from administration', function () {
    $agent = makeAgent();
    $token = makeToken($agent);
    [$other] = workspaceWith();

    withAgentToken($token)->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/v1/workspaces', ['name' => 'Mine'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'not_available_to_agents');
    $this->patchJson('/api/v1/me', ['name' => 'Renamed'])->assertForbidden();
    $this->getJson("/api/v1/workspaces/{$other->slug}/items")->assertNotFound();

    // No self-service: an agent cannot mint tokens, create agents, or invite people.
    $this->postJson("{$this->base}/agents", ['name' => 'Clone'])->assertForbidden();
    $this->postJson("{$this->base}/agents/{$agent}/tokens", ['name' => 'more', 'access' => 'write'])->assertForbidden();
    $this->getJson("{$this->base}/agents/{$agent}/tokens")->assertForbidden();
    $this->postJson("{$this->base}/invitations", ['email' => 'x@example.com', 'role' => 'member'])->assertForbidden();
});

it('does not let an agent sign in with a password', function () {
    $agent = User::findOrFail(makeAgent());
    $agent->forceFill(['password' => 'known-password'])->save();
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/auth/login', ['email' => $agent->email, 'password' => 'known-password'])
        ->assertUnprocessable();
});

it('lets only the owner or an admin manage an agent', function () {
    $agent = makeAgent();
    $stranger = addMember($this->workspace);
    $admin = addMember($this->workspace, WorkspaceRole::Admin);

    $this->actingAs($stranger);
    $this->patchJson("{$this->base}/agents/{$agent}", ['name' => 'Hijacked'])->assertForbidden();
    $this->postJson("{$this->base}/agents/{$agent}/tokens", ['name' => 'x', 'access' => 'write'])->assertForbidden();
    $this->deleteJson("{$this->base}/agents/{$agent}")->assertForbidden();
    $this->getJson("{$this->base}/agents")->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($admin)->patchJson("{$this->base}/agents/{$agent}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

it('never makes an agent an admin', function () {
    $agent = makeAgent();
    $admin = addMember($this->workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)->patchJson("{$this->base}/members/{$agent}", ['role' => 'admin'])->assertStatus(409);
    $this->patchJson("{$this->base}/members/{$agent}", ['role' => 'guest'])->assertOk();
});

it('revokes every token when the agent is removed and keeps its history', function () {
    $agent = makeAgent();
    $token = makeToken($agent);

    withAgentToken($token)->postJson("{$this->base}/items/WEB-1/comments", ['body' => 'Done'])->assertCreated();

    asPerson($this->owner)->deleteJson("{$this->base}/agents/{$agent}")->assertNoContent();

    expect(PersonalAccessToken::count())->toBe(0);
    $this->getJson("{$this->base}/agents")->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/items/WEB-1/comments")->assertJsonPath('data.0.author.name', 'Coder');

    withAgentToken($token)->getJson("{$this->base}/items")->assertUnauthorized();
});

it('does not show agents of another workspace', function () {
    makeAgent();
    [$other, $stranger] = workspaceWith();

    $this->actingAs($stranger)->getJson("/api/v1/workspaces/{$other->slug}/agents")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/agents")->assertNotFound();
});
