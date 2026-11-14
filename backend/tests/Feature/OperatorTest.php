<?php

use App\Models\AuditEvent;
use App\Models\User;

function operator(): User
{
    return User::factory()->create(['is_operator' => true]);
}

it('hides the operator area from everyone who is not an operator', function () {
    [$workspace, $owner] = workspaceWith();

    $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
    $this->actingAs($owner)->getJson('/api/v1/admin/overview')->assertNotFound();
    $this->getJson('/api/v1/admin/workspaces')->assertNotFound();
    $this->patchJson("/api/v1/admin/workspaces/{$workspace->id}", ['suspended' => true])->assertNotFound();
    $this->getJson('/api/v1/admin/audit')->assertNotFound();
});

it('shows an operator the outline of the installation', function () {
    [$workspace] = workspaceWith();
    addMember($workspace);
    workspaceWith();
    $this->actingAs($workspace->memberships()->first()->user)->postJson("/api/v1/workspaces/{$workspace->slug}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $this->postJson("/api/v1/workspaces/{$workspace->slug}/items", ['project' => 'WEB', 'title' => 'x'])->assertCreated();

    $this->actingAs(operator())->getJson('/api/v1/admin/overview')
        ->assertOk()->assertJsonPath('data.workspaces', 2)->assertJsonPath('data.people', 4);

    $this->getJson('/api/v1/admin/workspaces?q='.urlencode($workspace->slug))
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $workspace->slug)
        ->assertJsonPath('data.0.members', 2)
        ->assertJsonPath('data.0.items', 1)
        ->assertJsonPath('data.0.plan', 'free');

    $this->getJson('/api/v1/admin/users?limit=2')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('next_cursor', fn ($cursor) => is_string($cursor));
    $this->getJson('/api/v1/admin/workspaces?suspended=true')->assertOk()->assertJsonCount(0, 'data');
});

it('closes a suspended workspace to its members and opens it again', function () {
    [$workspace, $owner] = workspaceWith();
    $operator = operator();

    $this->actingAs($operator)->patchJson("/api/v1/admin/workspaces/{$workspace->id}", ['suspended' => true])
        ->assertOk()->assertJsonPath('data.suspended_at', fn ($at) => $at !== null);

    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$workspace->slug}/projects")
        ->assertForbidden()->assertJsonPath('error.code', 'workspace_suspended');

    $this->actingAs($operator)->patchJson("/api/v1/admin/workspaces/{$workspace->id}", ['suspended' => false, 'plan' => 'team'])
        ->assertOk()->assertJsonPath('data.suspended_at', null)->assertJsonPath('data.plan', 'team');
    $this->patchJson("/api/v1/admin/workspaces/{$workspace->id}", ['plan' => 'gold'])->assertUnprocessable();

    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$workspace->slug}/projects")->assertOk();
});

it('locks a suspended account out, together with its agents', function () {
    [$workspace, $owner] = workspaceWith();
    $agent = User::factory()->agent($workspace, $owner)->create();
    $token = $agent->createToken('ci', ['read', 'write'])->plainTextToken;
    $operator = operator();

    $this->actingAs($operator)->patchJson("/api/v1/admin/users/{$operator->id}", ['suspended' => true])->assertStatus(409);
    $this->patchJson("/api/v1/admin/users/{$agent->id}", ['suspended' => true])->assertNotFound();
    $this->patchJson("/api/v1/admin/users/{$owner->id}", ['suspended' => true])
        ->assertOk()->assertJsonPath('data.suspended_at', fn ($at) => $at !== null);

    $this->actingAs($owner->fresh())->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'account_suspended');

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin')->withToken($token)->getJson("/api/v1/workspaces/{$workspace->slug}/items")
        ->assertForbidden()->assertJsonPath('error.code', 'account_suspended');

    app('auth')->forgetGuards();
    $this->withoutToken()->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])
        ->assertForbidden()->assertJsonPath('error.code', 'account_suspended');

    asUser($operator)->patchJson("/api/v1/admin/users/{$owner->id}", ['suspended' => false])->assertOk();
    asUser($owner)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_operator', false);
});

it('grants and revokes the operator role from the command line', function () {
    $user = User::factory()->create();

    $this->artisan('operator:grant', ['email' => $user->email])->assertSuccessful();
    expect($user->refresh()->is_operator)->toBeTrue();

    $this->artisan('operator:grant', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_operator)->toBeFalse();

    $this->artisan('operator:grant', ['email' => 'nobody@example.com'])->assertFailed();
});

describe('audit log', function () {
    it('records access and settings changes without secrets or content', function () {
        [$workspace, $owner] = workspaceWith();
        $member = addMember($workspace);
        $base = "/api/v1/workspaces/{$workspace->slug}";

        $this->actingAs($owner)->patchJson("{$base}/members/{$member->id}", ['role' => 'admin'])->assertOk();
        $this->putJson("{$base}/ai", ['enabled' => false, 'api_key' => 'sk-ant-secret-value-0123456789'])->assertOk();
        $this->postJson("{$base}/projects", ['name' => 'Website', 'key' => 'WEB', 'description' => 'A long text'])->assertCreated();
        // Reading and ordinary work on items are not audit events.
        $this->getJson("{$base}/projects")->assertOk();
        $this->postJson("{$base}/items", ['project' => 'WEB', 'title' => 'x'])->assertCreated();
        // A refused request leaves no record.
        $this->actingAs($member)->deleteJson("{$base}/members/{$owner->id}");

        $log = $this->actingAs($owner)->getJson("{$base}/audit")->assertOk()->json('data');

        expect(array_column($log, 'action'))->toBe(['project.created', 'ai.settings_changed', 'member.role_changed'])
            ->and($log[2]['subject'])->toBe("user={$member->id}")
            ->and($log[2]['details'])->toBe(['role' => 'admin'])
            ->and($log[2]['actor'])->toBe(['id' => $owner->id, 'name' => $owner->name, 'type' => 'human'])
            ->and($log[1]['details']['api_key'])->toBe('(hidden)')
            ->and($log[0]['details'])->not->toHaveKey('description')
            ->and($log[0])->not->toHaveKey('ip')
            ->and(json_encode($log))->not->toContain('sk-ant-secret-value');

        $this->getJson("{$base}/audit?action=member.")->assertJsonCount(1, 'data');
        $this->getJson("{$base}/audit?actor={$member->id}")->assertJsonCount(0, 'data');
    });

    it('is for admins of that workspace only', function () {
        [$workspace, $owner] = workspaceWith();
        $member = addMember($workspace);
        [$other, $otherOwner] = workspaceWith();
        $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->slug}/projects", ['name' => 'Website', 'key' => 'WEB']);

        $this->actingAs($member)->getJson("/api/v1/workspaces/{$workspace->slug}/audit")->assertForbidden();
        $this->actingAs($otherOwner)->getJson("/api/v1/workspaces/{$workspace->slug}/audit")->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$other->slug}/audit")->assertOk()->assertJsonCount(0, 'data');
    });

    it('records sign-ins, failed sign-ins and what the operator does', function () {
        [$workspace, $owner] = workspaceWith();
        $operator = operator();

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'wrong-password']);
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertSuccessful();
        $this->postJson('/api/v1/auth/logout')->assertSuccessful();

        expect(AuditEvent::query()->orderBy('id')->pluck('action')->all())
            ->toBe(['account.sign_in_failed', 'account.signed_in', 'account.signed_out'])
            ->and(AuditEvent::query()->where('action', 'account.signed_out')->value('actor_id'))->toBe($owner->id)
            ->and(AuditEvent::query()->where('action', 'account.signed_in')->value('details'))->toBe(['email' => $owner->email, 'password' => '(hidden)'])
            ->and(json_encode(AuditEvent::query()->pluck('details')))->not->toContain('wrong-password');

        asUser($operator)->patchJson("/api/v1/admin/workspaces/{$workspace->id}", ['suspended' => true])->assertOk();

        $this->getJson('/api/v1/admin/audit?action=operator.')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'operator.workspace_changed')
            ->assertJsonPath('data.0.subject', "id={$workspace->id}")
            ->assertJsonPath('data.0.ip', '127.0.0.1');
        $this->getJson('/api/v1/admin/audit')->assertJsonCount(4, 'data');
    });
});
