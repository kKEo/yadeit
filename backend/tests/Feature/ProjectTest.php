<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;

function projectsUrl($workspace, string $suffix = ''): string
{
    return "/api/v1/workspaces/{$workspace->slug}/projects{$suffix}";
}

it('creates a project with the preset workflow', function () {
    [$workspace, $user] = workspaceWith(WorkspaceRole::Member);

    $response = $this->actingAs($user)->postJson(projectsUrl($workspace), ['name' => 'Website', 'key' => 'web'])
        ->assertCreated()
        ->assertJsonPath('data.key', 'WEB')
        ->assertJsonCount(7, 'data.states');

    $states = collect($response->json('data.states'));
    expect($states->pluck('group')->unique()->sort()->values()->all())
        ->toBe(['backlog', 'cancelled', 'completed', 'started', 'triage', 'unstarted'])
        ->and($states->where('is_default', true)->pluck('name')->all())->toBe(['Todo']);
});

it('validates the project key and keeps it unique per workspace', function () {
    [$workspace, $user] = workspaceWith();
    $this->actingAs($user)->postJson(projectsUrl($workspace), ['name' => 'A', 'key' => 'WEB'])->assertCreated();

    $this->postJson(projectsUrl($workspace), ['name' => 'B', 'key' => 'web'])->assertUnprocessable();
    $this->postJson(projectsUrl($workspace), ['name' => 'B', 'key' => '1AB'])->assertUnprocessable();
    $this->postJson(projectsUrl($workspace), ['name' => 'B', 'key' => 'TOOLONGKEY12'])->assertUnprocessable();

    // The same key is fine in another workspace.
    [$other, $otherUser] = workspaceWith();
    $this->actingAs($otherUser)->postJson(projectsUrl($other), ['name' => 'A', 'key' => 'WEB'])->assertCreated();
});

it('does not let guests create projects', function () {
    [$workspace, $guest] = workspaceWith(WorkspaceRole::Guest);

    $this->actingAs($guest)->postJson(projectsUrl($workspace), ['name' => 'X', 'key' => 'XX'])->assertForbidden();
});

it('renames and archives a project but never changes its key', function () {
    [$workspace, $user] = workspaceWith();
    $this->actingAs($user)->postJson(projectsUrl($workspace), ['name' => 'Website', 'key' => 'WEB']);

    $this->patchJson(projectsUrl($workspace, '/WEB'), ['name' => 'Site', 'key' => 'NEW', 'archived' => true])
        ->assertOk()
        ->assertJsonPath('data.name', 'Site')
        ->assertJsonPath('data.key', 'WEB')
        ->assertJsonPath('data.archived', true);

    $this->getJson(projectsUrl($workspace))->assertJsonMissing(['key' => 'WEB']);
    $this->getJson(projectsUrl($workspace, '?include_archived=1'))->assertJsonFragment(['key' => 'WEB']);

    $this->postJson("/api/v1/workspaces/{$workspace->slug}/items", ['project' => 'WEB', 'title' => 'x'])
        ->assertStatus(409);
});

it('lets only admins delete a project', function () {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $member = addMember($workspace);
    $this->actingAs($admin)->postJson(projectsUrl($workspace), ['name' => 'Website', 'key' => 'WEB']);
    $this->postJson("/api/v1/workspaces/{$workspace->slug}/items", ['project' => 'WEB', 'title' => 'x'])->assertCreated();

    $this->actingAs($member)->deleteJson(projectsUrl($workspace, '/WEB'))->assertForbidden();
    $this->actingAs($admin)->deleteJson(projectsUrl($workspace, '/WEB'))->assertNoContent();
    $this->getJson(projectsUrl($workspace, '/WEB'))->assertNotFound();
});

describe('states', function () {
    beforeEach(function () {
        [$this->workspace, $this->admin] = workspaceWith(WorkspaceRole::Admin);
        $this->actingAs($this->admin)->postJson(projectsUrl($this->workspace), ['name' => 'Website', 'key' => 'WEB']);
        $this->states = projectsUrl($this->workspace, '/WEB/states');
    });

    it('adds, renames and reorders states', function () {
        $id = $this->postJson($this->states, ['name' => 'QA', 'group' => 'started', 'color' => '#112233'])
            ->assertCreated()->json('data.id');

        $this->patchJson("{$this->states}/{$id}", ['name' => 'Testing', 'position' => 3])
            ->assertOk()->assertJsonPath('data.name', 'Testing');

        $this->postJson($this->states, ['name' => 'Testing', 'group' => 'started'])->assertUnprocessable();
        $this->postJson($this->states, ['name' => 'Other', 'group' => 'nonsense'])->assertUnprocessable();
    });

    it('keeps exactly one default state', function () {
        $backlog = collect($this->getJson($this->states)->json('data'))->firstWhere('name', 'Backlog')['id'];

        $this->patchJson("{$this->states}/{$backlog}", ['is_default' => true])->assertOk();

        $defaults = collect($this->getJson($this->states)->json('data'))->where('is_default', true);
        expect($defaults->pluck('name')->all())->toBe(['Backlog']);
    });

    it('refuses to delete the default state or a state in use', function () {
        $states = collect($this->getJson($this->states)->json('data'));
        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/items", ['project' => 'WEB', 'title' => 'x', 'state' => 'Backlog']);

        $this->deleteJson("{$this->states}/".$states->firstWhere('name', 'Todo')['id'])->assertStatus(409);
        $this->deleteJson("{$this->states}/".$states->firstWhere('name', 'Backlog')['id'])->assertStatus(409);
        $this->deleteJson("{$this->states}/".$states->firstWhere('name', 'In review')['id'])->assertNoContent();
    });

    it('is closed to non-admins and to other projects\' state ids', function () {
        $member = addMember($this->workspace);
        $this->postJson(projectsUrl($this->workspace), ['name' => 'Other', 'key' => 'OTH']);
        $foreignState = Project::query()->withoutGlobalScopes()->where('key', 'OTH')->first()
            ->states()->withoutGlobalScopes()->first()->id;

        $this->patchJson("{$this->states}/{$foreignState}", ['name' => 'Hijack'])->assertNotFound();
        $this->actingAs($member)->postJson($this->states, ['name' => 'QA', 'group' => 'started'])->assertForbidden();
    });
});

it('manages labels per project', function () {
    [$workspace, $user] = workspaceWith(WorkspaceRole::Member);
    $this->actingAs($user)->postJson(projectsUrl($workspace), ['name' => 'Website', 'key' => 'WEB']);
    $labels = projectsUrl($workspace, '/WEB/labels');

    $id = $this->postJson($labels, ['name' => 'frontend', 'color' => '#00ff00'])->assertCreated()->json('data.id');
    $this->postJson($labels, ['name' => 'frontend'])->assertUnprocessable();
    $this->patchJson("{$labels}/{$id}", ['name' => 'ui'])->assertOk()->assertJsonPath('data.name', 'ui');
    $this->getJson($labels)->assertJsonCount(1, 'data');
    $this->deleteJson("{$labels}/{$id}")->assertNoContent();
    $this->deleteJson("{$labels}/{$id}")->assertNotFound();
});
