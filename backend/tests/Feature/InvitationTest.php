<?php

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceInvitation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/** Invites an address as the given admin and returns the token from the email. */
function invite(Workspace $workspace, User $admin, string $email, string $role = 'member'): string
{
    Notification::fake();

    test()->actingAs($admin)
        ->postJson("/api/v1/workspaces/{$workspace->slug}/invitations", ['email' => $email, 'role' => $role])
        ->assertCreated();

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitation::class,
        function (WorkspaceInvitation $n, array $channels, AnonymousNotifiable $to) use ($email, &$token) {
            $token = $n->token;

            return $to->routes['mail'] === $email;
        });

    return $token;
}

it('emails an invitation link that points at the web app', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    Notification::fake();

    $this->actingAs($admin)
        ->postJson("/api/v1/workspaces/{$workspace->slug}/invitations", ['email' => 'new@example.com'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.role', 'member')
        ->assertJsonMissingPath('data.token_hash');

    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitation::class,
        fn (WorkspaceInvitation $n) => str_starts_with($n->url(), 'http://localhost:5173/invitations/'));

    // Only a hash of the token is stored.
    $stored = Invitation::firstOrFail();
    expect($stored->token_hash)->toHaveLength(64);
});

it('shows the invitation to the invited person before they sign in', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $token = invite($workspace, $admin, 'new@example.com');
    auth()->forgetGuards();

    $this->getJson("/api/v1/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.workspace.name', $workspace->name)
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.has_account', false);

    $this->getJson('/api/v1/invitations/not-a-real-token')->assertNotFound();
});

it('adds the invited user to the workspace and confirms their email', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $token = invite($workspace, $admin, 'new@example.com', 'guest');
    $invitee = User::factory()->unverified()->create(['email' => 'new@example.com']);

    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertOk()
        ->assertJsonPath('data.workspace.slug', $workspace->slug);

    expect($invitee->membershipIn($workspace)->role)->toBe(WorkspaceRole::Guest)
        ->and($invitee->fresh()->hasVerifiedEmail())->toBeTrue();

    // A used invitation cannot be used again.
    $this->postJson("/api/v1/invitations/{$token}/accept")->assertNotFound();
});

it('refuses an invitation opened by a different account', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $token = invite($workspace, $admin, 'new@example.com');
    $someoneElse = User::factory()->create();

    $this->actingAs($someoneElse)->postJson("/api/v1/invitations/{$token}/accept")->assertForbidden();
    expect($someoneElse->membershipIn($workspace))->toBeNull();
});

it('refuses an expired invitation', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $token = invite($workspace, $admin, 'new@example.com');
    $invitee = User::factory()->create(['email' => 'new@example.com']);

    $this->travel(Invitation::VALID_DAYS + 1)->days();

    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")->assertNotFound();
});

it('replaces an open invitation when the same address is invited again', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $first = invite($workspace, $admin, 'new@example.com');
    invite($workspace, $admin, 'new@example.com');

    expect($workspace->invitations()->count())->toBe(1);
    $this->getJson("/api/v1/invitations/{$first}")->assertNotFound();
});

it('restricts who may invite and whom', function () {
    [$workspace, $owner] = workspaceWith();
    $admin = addMember($workspace, WorkspaceRole::Admin);
    $member = addMember($workspace);
    $url = "/api/v1/workspaces/{$workspace->slug}/invitations";
    Notification::fake();

    $this->actingAs($member)->postJson($url, ['email' => 'a@example.com'])->assertForbidden();
    $this->actingAs($admin)->postJson($url, ['email' => 'a@example.com', 'role' => 'owner'])->assertForbidden();
    $this->actingAs($admin)->postJson($url, ['email' => $member->email])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['email']]]]);
    $this->actingAs($owner)->postJson($url, ['email' => 'a@example.com', 'role' => 'owner'])->assertCreated();
});

it('requires a confirmed email address to invite', function () {
    $unverified = User::factory()->unverified()->create();
    [$workspace] = workspaceWith(WorkspaceRole::Owner, $unverified);
    Notification::fake();

    $this->actingAs($unverified)
        ->postJson("/api/v1/workspaces/{$workspace->slug}/invitations", ['email' => 'a@example.com'])
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('lets an admin list and withdraw open invitations', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $token = invite($workspace, $admin, 'new@example.com');
    $url = "/api/v1/workspaces/{$workspace->slug}/invitations";

    $id = $this->actingAs($admin)->getJson($url)->assertJsonCount(1, 'data')->json('data.0.id');

    $this->deleteJson("{$url}/{$id}")->assertNoContent();
    $this->getJson("/api/v1/invitations/{$token}")->assertNotFound();
    $this->deleteJson("{$url}/{$id}")->assertNotFound();
});
