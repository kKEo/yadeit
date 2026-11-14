<?php

use App\Enums\WorkspaceRole;
use App\Models\Feedback;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Admin);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
});

function feedback(array $data = []): int
{
    return test()->postJson(test()->base.'/feedback', ['project' => 'WEB', 'title' => 'Please add dark mode', ...$data])->assertCreated()->json('data.id');
}

function demandOf(string $key): int
{
    return test()->getJson(test()->base."/items/{$key}")->json('data.demand');
}

it('collects feedback in an inbox, newest first', function () {
    feedback(['body' => 'My eyes hurt at night.']);
    feedback(['title' => 'Export to CSV']);

    $this->getJson("{$this->base}/feedback")
        ->assertOk()
        ->assertJsonPath('waiting', 2)
        ->assertJsonPath('data.0.title', 'Export to CSV')
        ->assertJsonPath('data.1.body', 'My eyes hurt at night.')
        ->assertJsonPath('data.1.status', 'new')
        ->assertJsonPath('data.1.source', 'app')
        ->assertJsonPath('data.1.from.name', $this->user->name)
        ->assertJsonPath('data.1.item', null);
});

it('accepts feedback as a new work item that carries its text', function () {
    $id = feedback(['body' => 'My eyes hurt at night.']);

    $this->postJson("{$this->base}/feedback/{$id}/accept", ['kind' => 'feature', 'priority' => 'high'])
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.item.key', 'WEB-1')
        ->assertJsonPath('data.item.demand', 1);

    $this->getJson("{$this->base}/items/WEB-1")
        ->assertJsonPath('data.title', 'Please add dark mode')
        ->assertJsonPath('data.kind', 'feature')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.demand', 1)
        ->assertJsonPath('data.description', "My eyes hurt at night.\n\n---\nFrom feedback #{$id}, sent by {$this->user->name}.");

    $this->getJson("{$this->base}/feedback")->assertJsonPath('waiting', 0)->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/feedback?status=accepted")->assertJsonCount(1, 'data');
});

it('adds more feedback to an existing item and counts the demand', function () {
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Dark mode'])->assertCreated();
    $first = feedback();
    $second = feedback(['title' => 'Night theme please']);
    $third = feedback(['title' => 'Too bright']);

    $this->postJson("{$this->base}/feedback/{$first}/accept", ['item' => 'web-1'])->assertOk()->assertJsonPath('data.item.demand', 1);
    $this->postJson("{$this->base}/feedback/{$second}/accept", ['item' => 'WEB-1'])->assertOk()->assertJsonPath('data.item.demand', 2);

    // A duplicate of linked feedback counts for the same item.
    $this->postJson("{$this->base}/feedback/{$third}/duplicate", ['of' => $first])
        ->assertOk()
        ->assertJsonPath('data.status', 'duplicate')
        ->assertJsonPath('data.duplicate_of', $first)
        ->assertJsonPath('data.item.key', 'WEB-1');

    expect(demandOf('WEB-1'))->toBe(3);

    $this->postJson("{$this->base}/feedback/{$first}/accept", ['item' => 'WEB-99'])->assertUnprocessable();
});

it('lowers the demand when a decision is undone, and moves duplicates along', function () {
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Dark mode'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Themes'])->assertCreated();
    $original = feedback();
    $duplicate = feedback(['title' => 'Same wish']);

    $this->postJson("{$this->base}/feedback/{$original}/accept", ['item' => 'WEB-1'])->assertOk();
    $this->postJson("{$this->base}/feedback/{$duplicate}/duplicate", ['of' => $original])->assertOk();
    expect(demandOf('WEB-1'))->toBe(2);

    // Moving the original to another item takes its duplicate with it.
    $this->postJson("{$this->base}/feedback/{$original}/accept", ['item' => 'WEB-2'])->assertOk();
    expect(demandOf('WEB-1'))->toBe(0)->and(demandOf('WEB-2'))->toBe(2);

    $this->postJson("{$this->base}/feedback/{$original}/reopen")->assertOk()->assertJsonPath('data.status', 'new')->assertJsonPath('data.item', null);
    expect(demandOf('WEB-2'))->toBe(0);

    $this->postJson("{$this->base}/feedback/{$original}/decline", ['reason' => 'Not planned'])
        ->assertOk()
        ->assertJsonPath('data.status', 'declined')
        ->assertJsonPath('data.decline_reason', 'Not planned');
});

it('does not let feedback be a duplicate of itself or of a duplicate', function () {
    $a = feedback();
    $b = feedback();
    $c = feedback();
    $this->postJson("{$this->base}/feedback/{$b}/duplicate", ['of' => $a])->assertOk();

    $this->postJson("{$this->base}/feedback/{$a}/duplicate", ['of' => $a])->assertUnprocessable();
    $this->postJson("{$this->base}/feedback/{$c}/duplicate", ['of' => $b])->assertUnprocessable();
    $this->postJson("{$this->base}/feedback/{$c}/duplicate", ['of' => 9999])->assertNotFound();
});

it('snoozes feedback out of the inbox until its time comes', function () {
    $id = feedback();

    $this->postJson("{$this->base}/feedback/{$id}/snooze", ['until' => now()->addDays(3)->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.snoozed_until', fn ($until) => $until !== null);

    $this->getJson("{$this->base}/feedback")->assertJsonCount(0, 'data')->assertJsonPath('waiting', 0);
    $this->getJson("{$this->base}/feedback?status=snoozed")->assertJsonCount(1, 'data');

    $this->travel(4)->days();
    $this->getJson("{$this->base}/feedback")->assertJsonCount(1, 'data')->assertJsonPath('data.0.snoozed_until', null);

    $this->postJson("{$this->base}/feedback/{$id}/snooze", ['until' => '2020-01-01'])->assertUnprocessable();
    $this->postJson("{$this->base}/feedback/{$id}/decline")->assertOk();
    $this->postJson("{$this->base}/feedback/{$id}/snooze", ['until' => now()->addDay()->toIso8601String()])->assertStatus(409);
});

it('sorts and filters items by demand', function () {
    foreach (['Quiet', 'Wanted', 'Most wanted'] as $title) {
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => $title])->assertCreated();
    }

    foreach (['WEB-2', 'WEB-3', 'WEB-3'] as $key) {
        $this->postJson("{$this->base}/feedback/".feedback().'/accept', ['item' => $key])->assertOk();
    }

    $this->getJson("{$this->base}/items?sort=-demand")->assertJsonPath('data.*.key', ['WEB-3', 'WEB-2', 'WEB-1'])->assertJsonPath('data.0.demand', 2);
    $this->getJson("{$this->base}/items?demand=1")->assertJsonCount(2, 'data');
    $this->getJson("{$this->base}/items?demand=0")->assertJsonPath('data.*.key', ['WEB-1']);
});

it('lets guests send feedback and see only their own', function () {
    feedback(['title' => 'From the team']);
    $guest = addMember($this->workspace, WorkspaceRole::Guest);

    $own = $this->actingAs($guest)->postJson("{$this->base}/feedback", ['project' => 'WEB', 'title' => 'From a guest'])->assertCreated()->json('data.id');

    $this->getJson("{$this->base}/feedback")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'From a guest')->assertJsonPath('waiting', null);
    $this->postJson("{$this->base}/feedback/{$own}/accept")->assertForbidden();
    $this->postJson("{$this->base}/feedback/{$own}/decline")->assertForbidden();
});

it('lets an agent send feedback but not triage it', function () {
    $id = feedback();
    $agent = User::factory()->agent($this->workspace, $this->user)->create(['name' => 'Coder']);
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin')->withToken($agent->createToken('t', ['read', 'write'])->plainTextToken);

    $this->postJson("{$this->base}/feedback", ['project' => 'WEB', 'title' => 'Users keep asking for SSO'])
        ->assertCreated()
        ->assertJsonPath('data.source', 'agent')
        ->assertJsonPath('data.from.name', 'Coder');

    foreach (['accept', 'decline', 'reopen'] as $action) {
        $this->postJson("{$this->base}/feedback/{$id}/{$action}")->assertForbidden()->assertJsonPath('error.code', 'not_available_to_agents');
    }
});

it('takes feedback from the public form once the project switches it on', function () {
    $this->getJson("{$this->base}/projects/WEB")->assertJsonPath('data.feedback_token', null);

    $token = $this->putJson("{$this->base}/projects/WEB/feedback-form", ['enabled' => true])->assertOk()->json('data.feedback_token');
    expect($token)->toHaveLength(40);
    $this->getJson("{$this->base}/projects/WEB")->assertJsonPath('data.feedback_token', $token);

    // Someone on the internet, with nothing but the link.
    app('auth')->forgetGuards();
    $this->flushSession();
    $form = "/api/v1/public/feedback/{$token}";

    $this->getJson($form)->assertOk()->assertExactJson(['data' => ['project' => 'Website']]);
    $this->postJson($form, ['title' => 'The checkout button is hidden on phones', 'body' => 'iPhone 15.', 'name' => 'Sam', 'email' => 'Sam@Example.com'])
        ->assertCreated()
        ->assertExactJson(['data' => ['received' => true]]);
    $this->postJson($form, ['title' => 'x'])->assertUnprocessable();
    $this->postJson($form, ['title' => 'Fine title', 'email' => 'not-an-address'])->assertUnprocessable();
    $this->getJson('/api/v1/public/feedback/'.str_repeat('z', 40))->assertNotFound();

    asUser($this->user)->getJson("{$this->base}/feedback")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source', 'public')
        ->assertJsonPath('data.0.from', ['name' => 'Sam', 'email' => 'sam@example.com']);
});

it('quietly drops what a spam robot sends and limits how fast anyone can send', function () {
    $token = $this->putJson("{$this->base}/projects/WEB/feedback-form", ['enabled' => true])->json('data.feedback_token');
    app('auth')->forgetGuards();
    $this->flushSession();
    $form = "/api/v1/public/feedback/{$token}";

    // The hidden field is filled in: answered like any other, stored nowhere.
    $this->postJson($form, ['title' => 'Buy cheap watches', 'website' => 'http://spam.example'])->assertCreated()->assertExactJson(['data' => ['received' => true]]);
    expect(Feedback::withoutGlobalScopes()->count())->toBe(0);

    for ($i = 0; $i < 4; $i++) {
        $this->postJson($form, ['title' => "Real feedback {$i}"])->assertCreated();
    }

    $this->postJson($form, ['title' => 'One too many'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    expect(Feedback::withoutGlobalScopes()->count())->toBe(4);
});

it('closes the public form for good when it is switched off', function () {
    $token = $this->putJson("{$this->base}/projects/WEB/feedback-form", ['enabled' => true])->json('data.feedback_token');
    $this->putJson("{$this->base}/projects/WEB/feedback-form", ['enabled' => false])->assertOk()->assertJsonPath('data.feedback_token', null);

    $this->getJson("/api/v1/public/feedback/{$token}")->assertNotFound();
    $this->postJson("/api/v1/public/feedback/{$token}", ['title' => 'Too late'])->assertNotFound();

    // Switching it on again gives a new link; the old one stays dead.
    $new = $this->putJson("{$this->base}/projects/WEB/feedback-form", ['enabled' => true])->json('data.feedback_token');
    expect($new)->not->toBe($token);

    $member = addMember($this->workspace);
    $this->actingAs($member)->putJson("{$this->base}/projects/WEB/feedback-form", ['enabled' => false])->assertForbidden();
});

it('keeps feedback inside its workspace', function () {
    $id = feedback();
    [$other, $stranger] = workspaceWith(WorkspaceRole::Admin);

    $this->actingAs($stranger);
    $this->getJson("/api/v1/workspaces/{$other->slug}/feedback")->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/workspaces/{$other->slug}/feedback/{$id}/accept")->assertNotFound();
    $this->postJson("{$this->base}/feedback/{$id}/accept")->assertNotFound();
});
