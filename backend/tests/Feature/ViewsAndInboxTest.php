<?php

use App\Enums\WorkspaceRole;
use App\Events\InboxChanged;
use App\Events\WorkspaceChanged;
use App\Notifications\ItemActivity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user)->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB']);
});

describe('saved views', function () {
    it('saves a view and drops unknown filter names', function () {
        $this->postJson("{$this->base}/views", [
            'name' => 'My open bugs',
            'filters' => ['kind' => 'bug', 'assignee' => 'me', 'open' => '1', 'evil' => 'x'],
            'layout' => 'board', 'group_by' => 'priority', 'sort' => '-priority',
        ])
            ->assertCreated()
            ->assertJsonPath('data.filters', ['kind' => 'bug', 'assignee' => 'me', 'open' => '1'])
            ->assertJsonPath('data.layout', 'board')
            ->assertJsonPath('data.is_shared', false);

        $this->postJson("{$this->base}/views", ['name' => 'x', 'layout' => 'gantt'])->assertUnprocessable();
        $this->postJson("{$this->base}/views", ['name' => 'x', 'sort' => 'password'])->assertUnprocessable();
    });

    it('keeps personal views private and shows shared ones to everyone', function () {
        $personal = $this->postJson("{$this->base}/views", ['name' => 'Personal'])->json('data.id');
        $shared = $this->postJson("{$this->base}/views", ['name' => 'Shared', 'is_shared' => true])->json('data.id');
        $other = addMember($this->workspace);

        $this->actingAs($other)->getJson("{$this->base}/views")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared);

        $this->patchJson("{$this->base}/views/{$personal}", ['name' => 'x'])->assertNotFound();
        $this->patchJson("{$this->base}/views/{$shared}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("{$this->base}/views/{$shared}")->assertForbidden();

        $admin = addMember($this->workspace, WorkspaceRole::Admin);
        $this->actingAs($admin)->patchJson("{$this->base}/views/{$shared}", ['name' => 'Renamed'])->assertOk();
        $this->actingAs($this->user)->deleteJson("{$this->base}/views/{$personal}")->assertNoContent();
    });

    it('does not let guests share views', function () {
        $guest = addMember($this->workspace, WorkspaceRole::Guest);

        $this->actingAs($guest)->postJson("{$this->base}/views", ['name' => 'Mine'])->assertCreated();
        $this->postJson("{$this->base}/views", ['name' => 'Ours', 'is_shared' => true])->assertForbidden();
    });
});

describe('inbox', function () {
    beforeEach(function () {
        $this->teammate = addMember($this->workspace);
        Notification::fake();
    });

    it('notifies the new assignee, but never the person who did it', function () {
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix it', 'assignee' => $this->teammate->id]);
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Mine', 'assignee' => 'me']);

        $this->getJson("{$this->base}/inbox")->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);

        $this->actingAs($this->teammate)->getJson("{$this->base}/inbox")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.event', 'assigned')
            ->assertJsonPath('data.0.item.key', 'WEB-1')
            ->assertJsonPath('data.0.actor.id', $this->user->id)
            ->assertJsonPath('data.0.read', false);

        Notification::assertSentTo($this->teammate, ItemActivity::class,
            fn (ItemActivity $n) => $n->url() === "http://localhost:5173/w/{$this->workspace->slug}/items/WEB-1");
        Notification::assertNotSentTo($this->user, ItemActivity::class);
    });

    it('notifies followers about comments and state changes', function () {
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix it']);

        $this->actingAs($this->teammate)->postJson("{$this->base}/items/WEB-1/comments", ['body' => 'On it']);
        $this->patchJson("{$this->base}/items/WEB-1", ['state' => 'Done', 'title' => 'Fixed']);

        $events = collect($this->actingAs($this->user)->getJson("{$this->base}/inbox")->json('data'));

        expect($events->pluck('event')->all())->toBe(['state_changed', 'commented'])
            ->and($events[0]['details'])->toBe(['from' => 'Todo', 'to' => 'Done']);

        // The teammate caused both, so their own inbox stays empty.
        $this->actingAs($this->teammate)->getJson("{$this->base}/inbox")->assertJsonCount(0, 'data');
    });

    it('respects the email preference and unconfirmed addresses', function () {
        $this->teammate->update(['email_notifications' => false]);
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix it', 'assignee' => $this->teammate->id]);

        Notification::assertNothingSent();
        $this->actingAs($this->teammate)->getJson("{$this->base}/inbox")->assertJsonCount(1, 'data');

        $this->patchJson('/api/v1/me', ['email_notifications' => true])->assertJsonPath('data.email_notifications', true);
    });

    it('marks entries read and unread, snoozes them and clears them', function () {
        foreach (['One', 'Two'] as $title) {
            $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => $title, 'assignee' => $this->teammate->id]);
        }

        $this->actingAs($this->teammate);
        $ids = collect($this->getJson("{$this->base}/inbox")->json('data'))->pluck('id');

        $this->patchJson("{$this->base}/inbox/{$ids[0]}", ['read' => true])->assertJsonPath('data.read', true)->assertJsonPath('unread_count', 1);
        $this->getJson("{$this->base}/inbox?unread=1")->assertJsonCount(1, 'data');
        $this->patchJson("{$this->base}/inbox/{$ids[0]}", ['read' => false])->assertJsonPath('unread_count', 2);

        // A snoozed entry disappears until its time comes.
        $this->patchJson("{$this->base}/inbox/{$ids[1]}", ['snoozed_until' => now()->addHours(3)->toIso8601String()])->assertOk();
        $this->getJson("{$this->base}/inbox")->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1);
        $this->travel(4)->hours();
        $this->getJson("{$this->base}/inbox")->assertJsonCount(2, 'data');
        $this->patchJson("{$this->base}/inbox/{$ids[1]}", ['snoozed_until' => now()->subHour()->toIso8601String()])->assertUnprocessable();

        $this->postJson("{$this->base}/inbox/read-all")->assertJsonPath('unread_count', 0);
        $this->deleteJson("{$this->base}/inbox/{$ids[0]}")->assertNoContent();
        $this->getJson("{$this->base}/inbox")->assertJsonCount(1, 'data');
    });

    it('keeps each person\'s inbox to themselves', function () {
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix it', 'assignee' => $this->teammate->id]);
        $id = $this->actingAs($this->teammate)->getJson("{$this->base}/inbox")->json('data.0.id');

        $this->actingAs($this->user)->patchJson("{$this->base}/inbox/{$id}", ['read' => true])->assertNotFound();
        $this->deleteJson("{$this->base}/inbox/{$id}")->assertNotFound();
    });
});

describe('realtime', function () {
    it('signals the workspace and the affected person, with identifiers only', function () {
        Event::fake([WorkspaceChanged::class, InboxChanged::class]);
        $teammate = addMember($this->workspace);

        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Secret title', 'assignee' => $teammate->id]);

        Event::assertDispatched(WorkspaceChanged::class, function (WorkspaceChanged $e) {
            expect($e->broadcastOn()->name)->toBe("private-workspace.{$this->workspace->id}")
                ->and($e->broadcastWith())->toBe(['key' => 'WEB-1', 'event' => 'created', 'actor_id' => $this->user->id]);

            return true;
        });

        Event::assertDispatched(InboxChanged::class, fn (InboxChanged $e) => $e->broadcastOn()->name === "private-user.{$teammate->id}");
    });

    it('lets only members listen to a workspace and only the owner to a user channel', function () {
        // Tests run with the null broadcaster; switch to the real one and register its channel rules.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
        [$other] = workspaceWith();
        $auth = fn (string $channel) => $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

        $auth("private-workspace.{$this->workspace->id}")->assertOk()->assertJsonStructure(['auth']);
        $auth("private-workspace.{$other->id}")->assertForbidden();
        $auth("private-user.{$this->user->id}")->assertOk();
        $auth('private-user.'.($this->user->id + 1))->assertForbidden();
    });
});
