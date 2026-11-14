<?php

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\WorkItemWriter;
use App\Support\CurrentWorkspace;

beforeEach(function () {
    [$this->workspace, $this->owner] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->owner);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();

    $this->agent = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Coder']);
    $this->token = $this->agent->createToken('test', ['read', 'write'])->plainTextToken;
});

function item(array $data = []): string
{
    return test()->postJson(test()->base.'/items', ['project' => 'WEB', 'title' => 'An item', ...$data])
        ->assertCreated()->json('data.key');
}

/** Calls as the agent would: bearer token, no browser session. */
function asAgent(?string $token = null): mixed
{
    app('auth')->forgetGuards();
    test()->flushSession();

    return test()->withoutHeader('Origin')->withToken($token ?? test()->token);
}

function asHuman(User $user): mixed
{
    test()->flushSession();

    return test()->withoutToken()->withHeader('Origin', 'http://localhost:5173')->actingAs($user);
}

it('lets a person delegate an item to an agent by name and keeps the assignee', function () {
    $key = item(['assignee' => 'me']);

    $this->patchJson("{$this->base}/items/{$key}", ['delegate' => 'coder'])
        ->assertOk()
        ->assertJsonPath('data.delegate.id', $this->agent->id)
        ->assertJsonPath('data.assignee.id', $this->owner->id);

    $this->getJson("{$this->base}/items?delegate={$this->agent->id}")->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/items?delegate=none")->assertJsonCount(0, 'data');

    $this->patchJson("{$this->base}/items/{$key}", ['delegate' => null])->assertOk()->assertJsonPath('data.delegate', null);
});

it('accepts only agents of the workspace as delegates', function () {
    $key = item();
    $person = addMember($this->workspace);

    $this->patchJson("{$this->base}/items/{$key}", ['delegate' => $person->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.delegate.0', 'The delegate must be an agent of this workspace. Available: Coder.');
});

it('lists ready work most urgent first and leaves out what cannot be started', function () {
    $low = item(['title' => 'Low']);
    $urgent = item(['title' => 'Urgent', 'priority' => 'urgent']);
    $blocked = item(['title' => 'Blocked', 'priority' => 'urgent']);
    $blocker = item(['title' => 'Blocker', 'state' => 'In progress']);
    item(['title' => 'Backlog', 'state' => 'Backlog']);
    item(['title' => 'Done', 'state' => 'Done']);
    item(['title' => 'Someone else\'s', 'assignee' => 'me']);
    $mine = item(['title' => 'Delegated to the agent', 'assignee' => 'me', 'delegate' => 'Coder']);
    $waiting = item(['title' => 'Waiting']);
    $this->patchJson("{$this->base}/items/{$waiting}", ['needs_input' => true])->assertOk();
    $this->postJson("{$this->base}/items/{$blocker}/relations", ['type' => 'blocks', 'item' => $blocked])->assertCreated();

    asAgent()->getJson("{$this->base}/items/ready")
        ->assertOk()
        ->assertJsonPath('data.*.key', [$urgent, $low, $mine]);

    // Once the blocker is done, the blocked item becomes ready.
    asHuman($this->owner)->patchJson("{$this->base}/items/{$blocker}", ['state' => 'Done'])->assertOk();

    asAgent()->getJson("{$this->base}/items/ready?limit=2")
        ->assertJsonPath('data.*.key', [$urgent, $blocked]);
});

it('shows a person the free items and their own, not ones an agent holds', function () {
    $free = item(['title' => 'Free']);
    $mine = item(['title' => 'Mine', 'assignee' => 'me']);
    item(['title' => 'With the agent', 'delegate' => 'Coder']);

    $this->getJson("{$this->base}/items/ready")->assertJsonPath('data.*.key', [$free, $mine]);
});

it('claims an item for an agent and starts it', function () {
    $key = item();

    asAgent()->postJson("{$this->base}/items/{$key}/claim")
        ->assertOk()
        ->assertJsonPath('data.delegate.id', $this->agent->id)
        ->assertJsonPath('data.assignee', null)
        ->assertJsonPath('data.state.name', 'In progress')
        ->assertJsonPath('data.version', 2);

    // Claiming what you already hold is not an error and changes nothing.
    $this->postJson("{$this->base}/items/{$key}/claim")->assertOk()->assertJsonPath('data.version', 2);

    $this->getJson("{$this->base}/items/{$key}/activity")
        ->assertJsonPath('data.0.event', 'claimed')
        ->assertJsonPath('data.0.actor.id', $this->agent->id)
        ->assertJsonPath('data.0.changes.state.to', 'In progress');
});

it('gives an item to exactly one of two agents', function () {
    $key = item();
    $rival = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Rival']);
    $rivalToken = $rival->createToken('test', ['read', 'write'])->plainTextToken;

    asAgent()->postJson("{$this->base}/items/{$key}/claim")->assertOk();

    asAgent($rivalToken)->postJson("{$this->base}/items/{$key}/claim")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_claimed')
        ->assertJsonPath('error.details.claimed_by.name', 'Coder');

    $this->getJson("{$this->base}/items/ready")->assertJsonCount(0, 'data');
});

it('never hands one item to two claimers, whatever the order of writes', function () {
    $key = item();
    $rival = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Rival']);
    $writer = app(WorkItemWriter::class);

    // Both callers loaded the item while it was free; the second write must still lose.
    $outcomes = app(CurrentWorkspace::class)->runIn($this->workspace, function () use ($key, $rival, $writer) {
        $seenByFirst = WorkItem::findByKey($key);
        $seenBySecond = WorkItem::findByKey($key);
        $writer->claim($seenByFirst, $this->agent);

        try {
            $writer->claim($seenBySecond, $rival);

            return 'both won';
        } catch (ApiException $e) {
            return $e->errorCode;
        }
    });

    expect($outcomes)->toBe('already_claimed');
});

it('refuses to claim blocked, closed or untriaged items', function () {
    $blocked = item();
    $blocker = item();
    $done = item(['state' => 'Done']);
    $triage = item(['state' => 'Triage']);
    $this->postJson("{$this->base}/items/{$blocker}/relations", ['type' => 'blocks', 'item' => $blocked])->assertCreated();

    asAgent()->postJson("{$this->base}/items/{$blocked}/claim")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'blocked')
        ->assertJsonPath('error.details.blocked_by', [$blocker]);

    $this->postJson("{$this->base}/items/{$done}/claim")->assertStatus(409)->assertJsonPath('error.code', 'not_claimable');
    $this->postJson("{$this->base}/items/{$triage}/claim")->assertStatus(409)->assertJsonPath('error.code', 'not_claimable');
});

it('lets a person claim by becoming the assignee', function () {
    $key = item();
    $other = addMember($this->workspace);

    $this->postJson("{$this->base}/items/{$key}/claim")
        ->assertOk()
        ->assertJsonPath('data.assignee.id', $this->owner->id)
        ->assertJsonPath('data.delegate', null);

    asHuman($other)->postJson("{$this->base}/items/{$key}/claim")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_claimed');
});

it('releases a claim so that someone else can take the item', function () {
    $key = item();
    $rival = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Rival']);
    $rivalToken = $rival->createToken('test', ['read', 'write'])->plainTextToken;

    asAgent()->postJson("{$this->base}/items/{$key}/claim")->assertOk();

    asAgent($rivalToken)->deleteJson("{$this->base}/items/{$key}/claim")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_claimed_by_you');

    asAgent()->deleteJson("{$this->base}/items/{$key}/claim")->assertOk()->assertJsonPath('data.delegate', null);
    asAgent($rivalToken)->postJson("{$this->base}/items/{$key}/claim")->assertOk()->assertJsonPath('data.delegate.name', 'Rival');
});

it('pauses on a question and resumes when a person answers', function () {
    $key = item(['assignee' => 'me']);

    asAgent()->postJson("{$this->base}/items/{$key}/claim")->assertOk();

    $this->postJson("{$this->base}/items/{$key}/ask", ['question' => 'Which database?'])
        ->assertOk()
        ->assertJsonPath('data.needs_input', true)
        ->assertJsonPath('data.comment_count', 1);

    // The assignee, who answers for the work, is told.
    expect(InboxItem::withoutGlobalScopes()->where('user_id', $this->owner->id)->where('event', 'input_requested')->count())->toBe(1);

    $this->getJson("{$this->base}/items?needs_input=1")->assertJsonCount(1, 'data');

    // The agent's own follow-up does not count as an answer.
    $this->postJson("{$this->base}/items/{$key}/comments", ['body' => 'Still waiting'])->assertCreated();
    $this->getJson("{$this->base}/items/{$key}")->assertJsonPath('data.needs_input', true);

    asHuman($this->owner)->postJson("{$this->base}/items/{$key}/comments", ['body' => 'PostgreSQL'])->assertCreated();

    $this->getJson("{$this->base}/items/{$key}")->assertJsonPath('data.needs_input', false);
    $this->getJson("{$this->base}/items/{$key}/activity")->assertJsonPath('data.0.event', 'input_provided');
});

it('submits work for review with a summary and tells the owner', function () {
    $key = item();

    asAgent()->postJson("{$this->base}/items/{$key}/claim")->assertOk();

    $this->postJson("{$this->base}/items/{$key}/submit-for-review", ['summary' => 'Done, see the pull request.'])
        ->assertOk()
        ->assertJsonPath('data.state.name', 'In review')
        ->assertJsonPath('data.delegate.id', $this->agent->id)
        ->assertJsonPath('data.comment_count', 1);

    // Nobody is assigned, so the agent's owner is the one who hears about it.
    expect(InboxItem::withoutGlobalScopes()->where('user_id', $this->owner->id)->where('event', 'submitted_for_review')->count())->toBe(1);
});

it('explains when a project has no review state', function () {
    $key = item();
    $admin = addMember($this->workspace, WorkspaceRole::Admin);
    $states = asHuman($admin)->getJson("{$this->base}/projects/WEB/states")->json('data');
    $review = collect($states)->firstWhere('name', 'In review');
    $this->deleteJson("{$this->base}/projects/WEB/states/{$review['id']}")->assertNoContent();

    asAgent()->postJson("{$this->base}/items/{$key}/submit-for-review")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'no_review_state');
});

it('keeps read-only tokens and guests from claiming', function () {
    $key = item();
    $readOnly = $this->agent->createToken('ro', ['read'])->plainTextToken;
    $guest = addMember($this->workspace, WorkspaceRole::Guest);

    asAgent($readOnly)->postJson("{$this->base}/items/{$key}/claim")->assertForbidden();
    asAgent($readOnly)->getJson("{$this->base}/items/ready")->assertOk();
    asHuman($guest)->postJson("{$this->base}/items/{$key}/claim")->assertForbidden();
});

it('marks blocked items and counts finished sub-items in lists and details', function () {
    $parent = item(['title' => 'Parent']);
    $childA = item(['title' => 'Child A', 'parent' => $parent, 'state' => 'Done']);
    item(['title' => 'Child B', 'parent' => $parent]);
    $blocker = item(['title' => 'Blocker']);
    $this->postJson("{$this->base}/items/{$blocker}/relations", ['type' => 'blocks', 'item' => $parent])->assertCreated();

    $rows = collect($this->getJson("{$this->base}/items?sort=sequence")->assertOk()->json('data'))->keyBy('key');

    expect($rows[$parent])->toMatchArray(['blocked' => true, 'sub_items' => ['total' => 2, 'done' => 1]])
        ->and($rows[$childA])->toMatchArray(['blocked' => false, 'sub_items' => null])
        ->and($rows[$blocker]['blocked'])->toBeFalse();

    $this->getJson("{$this->base}/items/{$parent}")
        ->assertJsonPath('data.blocked', true)
        ->assertJsonPath('data.sub_items', ['total' => 2, 'done' => 1]);

    $this->getJson("{$this->base}/items?blocked=1")->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', $parent);
    $this->getJson("{$this->base}/items?blocked=0")->assertJsonCount(3, 'data');

    // Finishing the blocker frees the item.
    $this->patchJson("{$this->base}/items/{$blocker}", ['state' => 'Done'])->assertOk();
    $this->getJson("{$this->base}/items/{$parent}")->assertJsonPath('data.blocked', false);
    $this->getJson("{$this->base}/items?blocked=1")->assertJsonCount(0, 'data');
});

describe('access that must not outlive a membership', function () {
    beforeEach(function () {
        [$this->ws, $this->admin] = workspaceWith(WorkspaceRole::Admin);
        $this->member = addMember($this->ws);
        $this->api = "/api/v1/workspaces/{$this->ws->slug}";
        $this->actingAs($this->admin)->postJson("{$this->api}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
        $this->postJson("{$this->api}/items", ['project' => 'WEB', 'title' => 'x'])->assertCreated();
        $this->bot = User::factory()->agent($this->ws, $this->member)->create();
        $this->secret = $this->bot->createToken('kept', ['read', 'write'])->plainTextToken;
        $this->asBot = function () {
            app('auth')->forgetGuards();
            $this->flushSession();

            return $this->withoutHeader('Origin')->withToken($this->secret);
        };
    });

    it('stops a removed member\'s agent and their notifications', function () {
        ($this->asBot)()->getJson("{$this->api}/items")->assertOk();
        asUser($this->member)->putJson("{$this->api}/items/WEB-1/subscription")->assertSuccessful();

        asUser($this->admin)->deleteJson("{$this->api}/members/{$this->member->id}")->assertNoContent();

        expect($this->bot->tokens()->count())->toBe(0)
            ->and(DB::table('subscriptions')->where('user_id', $this->member->id)->count())->toBe(0);
        ($this->asBot)()->getJson("{$this->api}/items")->assertUnauthorized();

        // Even with a token created some other way, the agent has nobody to work for.
        $this->secret = $this->bot->createToken('again', ['read', 'write'])->plainTextToken;
        ($this->asBot)()->getJson("{$this->api}/items")->assertForbidden()->assertJsonPath('error.code', 'agent_without_owner');
    });

    it('stops the agent of a member who becomes a guest', function () {
        asUser($this->admin)->patchJson("{$this->api}/members/{$this->member->id}", ['role' => 'guest'])->assertOk();

        ($this->asBot)()->getJson("{$this->api}/items")->assertUnauthorized();
    });
});
