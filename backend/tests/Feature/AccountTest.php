<?php

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Sanctum\PersonalAccessToken;

function seedContent($workspace, User $user): void
{
    $base = "/api/v1/workspaces/{$workspace->slug}";
    test()->actingAs($user)->postJson("{$base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    test()->postJson("{$base}/items", ['project' => 'WEB', 'title' => 'Fix login', 'description' => 'Details'])->assertCreated();
    test()->postJson("{$base}/items/WEB-1/comments", ['body' => 'My comment'])->assertCreated();
    test()->postJson("{$base}/items/WEB-1/checklist", ['text' => 'Works on mobile'])->assertCreated();
    test()->postJson("{$base}/pages", ['title' => 'Handbook', 'body' => 'How we work'])->assertCreated();
}

describe('personal export', function () {
    it('downloads what the person wrote and belongs to, and nobody else\'s', function () {
        [$workspace, $owner] = workspaceWith();
        $member = addMember($workspace);
        seedContent($workspace, $owner);
        $this->actingAs($member)->postJson("/api/v1/workspaces/{$workspace->slug}/items/WEB-1/comments", ['body' => 'Someone else'])->assertCreated();

        $response = $this->actingAs($owner)->get('/api/v1/me/export')->assertOk();

        expect($response->headers->get('Content-Disposition'))->toContain('attachment; filename="yadeit-my-data-');
        $data = $response->json();
        expect($data['profile']['email'])->toBe($owner->email)
            ->and($data['workspaces'][0]['slug'])->toBe($workspace->slug)
            ->and($data['workspaces'][0]['role'])->toBe('owner')
            ->and(array_column($data['items_created'], 'key'))->toBe(['WEB-1'])
            ->and(array_column($data['comments'], 'body'))->toBe(['My comment'])
            ->and($data['comments'][0]['item'])->toBe('WEB-1')
            ->and(array_column($data['pages'], 'title'))->toContain('Handbook')
            ->and(json_encode($data))->not->toContain('Someone else')->not->toContain('password');

        expect(AuditEvent::query()->where('action', 'account.exported')->value('actor_id'))->toBe($owner->id);
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/me/export')->assertUnauthorized();
    });
});

describe('workspace export', function () {
    it('gives an owner the whole workspace as one file', function () {
        [$workspace, $owner] = workspaceWith();
        $agent = User::factory()->agent($workspace, $owner)->create();
        seedContent($workspace, $owner);
        [$other, $otherOwner] = workspaceWith();
        seedContent($other, $otherOwner);
        $this->actingAs($otherOwner)->patchJson("/api/v1/workspaces/{$other->slug}/items/WEB-1", ['title' => 'Foreign item'])->assertOk();

        $response = $this->actingAs($owner)->get("/api/v1/workspaces/{$workspace->slug}/export")->assertOk();
        $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        expect($response->headers->get('Content-Disposition'))->toContain("yadeit-{$workspace->slug}-")
            ->and($data['workspace']['slug'])->toBe($workspace->slug)
            ->and(collect($data['members'])->firstWhere('type', 'agent')['email'])->toBeNull()
            ->and(collect($data['members'])->firstWhere('type', 'human')['email'])->toBe($owner->email)
            ->and(collect($data['projects'])->firstWhere('key', 'WEB')['states'])->toHaveCount(7)
            ->and($item = collect($data['items'])->firstWhere('key', 'WEB-1'))->not->toBeNull()
            ->and($item['title'])->toBe('Fix login')
            ->and($item['created_by'])->toBe($owner->name)
            ->and($item['comments'][0]['body'])->toBe('My comment')
            ->and($item['checklist'])->toBe([['text' => 'Works on mobile', 'done' => false]])
            ->and(array_column($data['pages'], 'title'))->toContain('Handbook')
            ->and($response->streamedContent())->not->toContain('Foreign item');

        expect($agent->isAgent())->toBeTrue();
    });

    it('is for owners only', function () {
        [$workspace, $owner] = workspaceWith();
        $admin = addMember($workspace, WorkspaceRole::Admin);
        $agent = User::factory()->agent($workspace, $owner)->create();
        $token = $agent->createToken('ci', ['read', 'write'])->plainTextToken;

        $this->actingAs($admin)->getJson("/api/v1/workspaces/{$workspace->slug}/export")->assertForbidden();

        app('auth')->forgetGuards();
        $this->flushSession();
        $this->withoutHeader('Origin')->withToken($token)->getJson("/api/v1/workspaces/{$workspace->slug}/export")
            ->assertForbidden()->assertJsonPath('error.code', 'not_available_to_agents');
    });
});

describe('deleting an account', function () {
    it('needs the password', function () {
        [, $owner] = workspaceWith();

        $this->actingAs($owner)->deleteJson('/api/v1/me')->assertUnprocessable();
        $this->deleteJson('/api/v1/me', ['password' => 'not-it'])->assertUnprocessable()->assertJsonPath('error.details.fields.password.0', 'This is not your password.');
        expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
    });

    it('refuses while other people depend on the account as the only owner', function () {
        [$workspace, $owner] = workspaceWith();
        $member = addMember($workspace);

        $this->actingAs($owner)->deleteJson('/api/v1/me', ['password' => 'password'])
            ->assertStatus(409)->assertJsonPath('error.code', 'sole_owner')->assertJsonPath('error.details.workspaces', [$workspace->slug]);

        // With a second owner the account can go and the workspace stays.
        $workspace->memberships()->where('user_id', $member->id)->update(['role' => 'owner']);
        $this->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

        expect(User::query()->whereKey($owner->id)->exists())->toBeFalse()
            ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    });

    it('removes the account, its agents and the workspace nobody else is in, and keeps shared work', function () {
        [$solo, $user] = workspaceWith();
        [$shared, $sharedOwner] = workspaceWith();
        $shared->memberships()->create(['user_id' => $user->id, 'role' => WorkspaceRole::Member]);
        $agent = User::factory()->agent($shared, $user)->create();
        $agent->createToken('ci', ['read', 'write']);
        $this->actingAs($sharedOwner)->postJson("/api/v1/workspaces/{$shared->slug}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/workspaces/{$shared->slug}/items", ['project' => 'WEB', 'title' => 'Shared work'])->assertCreated();

        $this->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

        expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
            ->and(User::query()->whereKey($agent->id)->exists())->toBeFalse()
            ->and(PersonalAccessToken::query()->where('tokenable_id', $agent->id)->exists())->toBeFalse()
            ->and(Workspace::query()->whereKey($solo->id)->exists())->toBeFalse()
            ->and(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue();

        // The item stays, without a name on it.
        asUser($sharedOwner)->getJson("/api/v1/workspaces/{$shared->slug}/items/WEB-1")->assertOk()->assertJsonPath('data.title', 'Shared work');

        $event = AuditEvent::query()->where('action', 'account.deleted')->firstOrFail();
        expect($event->actor_name)->toBe($user->name)->and($event->details)->toBe(['password' => '(hidden)']);
    });

    it('keeps the last operator', function () {
        $operator = User::factory()->create(['is_operator' => true]);

        $this->actingAs($operator)->deleteJson('/api/v1/me', ['password' => 'password'])->assertStatus(409)->assertJsonPath('error.code', 'last_operator');

        User::factory()->create(['is_operator' => true]);
        $this->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();
    });
});
