<?php

use App\Enums\WorkspaceRole;

it('lists members with owners first', function () {
    [$workspace, $owner] = workspaceWith();
    $guest = addMember($workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)->getJson("/api/v1/workspaces/{$workspace->slug}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $owner->id)
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.1.role', 'guest');
});

it('lets an admin change a member\'s role', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $member = addMember($workspace);

    $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$workspace->slug}/members/{$member->id}", ['role' => 'guest'])
        ->assertOk()
        ->assertJsonPath('data.role', 'guest');
});

it('does not let members change roles', function () {
    [$workspace] = workspaceWith();
    $member = addMember($workspace);
    $other = addMember($workspace);

    $this->actingAs($member)->patchJson("/api/v1/workspaces/{$workspace->slug}/members/{$other->id}", ['role' => 'admin'])
        ->assertForbidden();
});

it('does not let an admin promote to owner or change an owner', function () {
    [$workspace, $owner] = workspaceWith();
    $admin = addMember($workspace, WorkspaceRole::Admin);
    $member = addMember($workspace);
    $base = "/api/v1/workspaces/{$workspace->slug}/members";

    $this->actingAs($admin)->patchJson("{$base}/{$member->id}", ['role' => 'owner'])->assertForbidden();
    $this->patchJson("{$base}/{$owner->id}", ['role' => 'member'])->assertForbidden();
    $this->deleteJson("{$base}/{$owner->id}")->assertForbidden();
});

it('keeps at least one owner', function () {
    [$workspace, $owner] = workspaceWith();
    $base = "/api/v1/workspaces/{$workspace->slug}/members/{$owner->id}";

    $this->actingAs($owner)->patchJson($base, ['role' => 'admin'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
    $this->deleteJson($base)->assertStatus(409);

    addMember($workspace, WorkspaceRole::Owner);
    $this->patchJson($base, ['role' => 'admin'])->assertOk();
});

it('lets a member leave and an admin remove a member', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $leaver = addMember($workspace);
    $removed = addMember($workspace);
    $base = "/api/v1/workspaces/{$workspace->slug}/members";

    $this->actingAs($leaver)->deleteJson("{$base}/{$leaver->id}")->assertNoContent();
    $this->getJson("/api/v1/workspaces/{$workspace->slug}")->assertNotFound();

    $this->actingAs($admin)->deleteJson("{$base}/{$removed->id}")->assertNoContent();
    expect($workspace->memberships()->count())->toBe(1);
});

it('does not let a member remove someone else', function () {
    [$workspace] = workspaceWith();
    $member = addMember($workspace);
    $other = addMember($workspace);

    $this->actingAs($member)->deleteJson("/api/v1/workspaces/{$workspace->slug}/members/{$other->id}")
        ->assertForbidden();
});
