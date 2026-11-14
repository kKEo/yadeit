<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;

it('creates a workspace with the creator as owner and a sample project', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/workspaces', ['name' => 'Acme Design'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'acme-design')
        ->assertJsonPath('data.role', 'owner');

    $this->getJson('/api/v1/workspaces/acme-design/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', 'DEMO');

    $this->getJson('/api/v1/workspaces/acme-design/items?sort=sequence')
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.key', 'DEMO-1')
        ->assertJsonPath('data.0.assignee.id', $user->id);

    $this->getJson('/api/v1/me')->assertJsonPath('data.workspaces.0.slug', 'acme-design');
});

it('gives a second workspace with the same name a different slug', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson('/api/v1/workspaces', ['name' => 'Acme'])->assertJsonPath('data.slug', 'acme');
    $this->postJson('/api/v1/workspaces', ['name' => 'Acme'])->assertJsonPath('data.slug', 'acme-2');
});

it('refuses reserved, malformed and taken slugs', function (string $slug) {
    Workspace::factory()->create(['slug' => 'taken']);
    $this->actingAs(User::factory()->create());

    $this->postJson('/api/v1/workspaces', ['name' => 'X', 'slug' => $slug])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['slug']]]]);
})->with(['admin', 'Has Spaces', '-leading', 'taken']);

it('lists only the workspaces the user belongs to', function () {
    [$mine, $user] = workspaceWith(WorkspaceRole::Member);
    Workspace::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/workspaces')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.role', 'member');
});

it('lets admins rename a workspace but not members', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $member = addMember($workspace);

    $this->actingAs($member)->patchJson("/api/v1/workspaces/{$workspace->slug}", ['name' => 'Nope'])
        ->assertForbidden();

    $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$workspace->slug}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

it('lets only an owner delete a workspace', function () {
    [$workspace, $owner] = workspaceWith();
    $admin = addMember($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$workspace->slug}")->assertForbidden();
    $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$workspace->slug}")->assertNoContent();

    expect(Workspace::find($workspace->id))->toBeNull();
});

describe('tenant isolation', function () {
    it('answers 404 for every workspace route when the user is not a member', function (string $method, string $path) {
        [$other] = workspaceWith();
        $otherMember = addMember($other);
        $project = Project::factory()->create(['workspace_id' => $other->id]);
        [, $outsider] = workspaceWith();

        $path = str_replace(
            ['{slug}', '{user}', '{project}'],
            [$other->slug, (string) $otherMember->id, $project->key],
            $path,
        );

        $this->actingAs($outsider)->json($method, "/api/v1/workspaces/{$path}", ['name' => 'x', 'role' => 'admin', 'email' => 'x@example.com'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    })->with([
        ['GET', '{slug}'],
        ['PATCH', '{slug}'],
        ['DELETE', '{slug}'],
        ['GET', '{slug}/members'],
        ['PATCH', '{slug}/members/{user}'],
        ['DELETE', '{slug}/members/{user}'],
        ['GET', '{slug}/invitations'],
        ['POST', '{slug}/invitations'],
        ['GET', '{slug}/projects'],
        ['GET', '{slug}/projects/{project}'],
    ]);

    it('answers 404 for an unknown workspace exactly as for a foreign one', function () {
        [$other] = workspaceWith();
        [, $outsider] = workspaceWith();

        $foreign = $this->actingAs($outsider)->getJson("/api/v1/workspaces/{$other->slug}");
        $unknown = $this->getJson('/api/v1/workspaces/does-not-exist');

        expect($unknown->status())->toBe(404)->and($unknown->json())->toBe($foreign->json());
    });

    it('cannot reach another workspace\'s project through its own workspace URL', function () {
        [$mine, $user] = workspaceWith();
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/workspaces/{$mine->slug}/projects/{$foreignProject->key}")
            ->assertNotFound();
    });

    it('lists only the current workspace\'s projects', function () {
        [$mine, $user] = workspaceWith();
        Project::factory()->create(['workspace_id' => $mine->id, 'name' => 'Mine']);
        Project::factory()->create(['name' => 'Theirs']);

        $this->actingAs($user)->getJson("/api/v1/workspaces/{$mine->slug}/projects")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    });

    it('returns nothing from tenant models when no workspace is set', function () {
        Project::factory()->count(2)->create();

        expect(Project::count())->toBe(0)
            ->and(app(CurrentWorkspace::class)->runUnscoped(fn () => Project::count()))->toBe(2);
    });

    it('refuses to create tenant rows outside a workspace', function () {
        Project::create(['key' => 'X', 'name' => 'Orphan']);
    })->throws(LogicException::class);

    it('requires sign-in before revealing anything', function () {
        [$workspace] = workspaceWith();

        $this->getJson("/api/v1/workspaces/{$workspace->slug}")->assertUnauthorized();
    });
});
