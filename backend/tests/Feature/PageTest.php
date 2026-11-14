<?php

use App\Enums\WorkspaceRole;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
});

function page(array $data = []): array
{
    return test()->postJson(test()->base.'/pages', ['title' => 'Handbook', ...$data])->assertCreated()->json('data');
}

it('creates a draft, edits it and counts versions', function () {
    $created = page(['body' => '# Hello', 'project' => 'web']);

    expect($created)->toMatchArray(['title' => 'Handbook', 'status' => 'draft', 'version' => 1, 'public_token' => null, 'body' => '# Hello'])
        ->and($created['project'])->toBe(['key' => 'WEB', 'name' => 'Website']);

    $this->patchJson("{$this->base}/pages/{$created['id']}", ['body' => '# Hello again', 'expected_version' => 1])
        ->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.updated_by.id', $this->user->id);

    // Saving the same text again is not a new version.
    $this->patchJson("{$this->base}/pages/{$created['id']}", ['body' => '# Hello again'])->assertJsonPath('data.version', 2);

    $this->patchJson("{$this->base}/pages/{$created['id']}", ['title' => 'Late', 'expected_version' => 1])
        ->assertStatus(412)
        ->assertJsonPath('error.details.current_version', 2);
});

it('lists pages without their text and filters them', function () {
    page(['title' => 'Workspace rules', 'status' => 'published']);
    page(['title' => 'Release checklist', 'project' => 'WEB']);

    $this->getJson("{$this->base}/pages")->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.body');
    $this->getJson("{$this->base}/pages?project=WEB")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Release checklist');
    $this->getJson("{$this->base}/pages?project=none")->assertJsonPath('data.0.title', 'Workspace rules');
    $this->getJson("{$this->base}/pages?status=published")->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/pages?q=checkl")->assertJsonCount(1, 'data');
});

it('shares a published page by link and takes the link away again', function () {
    $id = page(['body' => 'Public text'])['id'];

    $this->patchJson("{$this->base}/pages/{$id}", ['public' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.public.0', 'Publish the page first: only published pages can be shared by link.');

    $token = $this->patchJson("{$this->base}/pages/{$id}", ['status' => 'published', 'public' => true])->assertOk()->json('data.public_token');
    expect($token)->toHaveLength(40);

    // Signed out, with nothing but the link.
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->getJson("/api/v1/public/pages/{$token}")
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['title', 'body', 'updated_at']])
        ->assertJsonPath('data.body', 'Public text');
    $this->getJson('/api/v1/public/pages/'.str_repeat('x', 40))->assertNotFound();

    // Back to a draft: the old link is dead, and publishing again does not revive it.
    asUser($this->user)->patchJson("{$this->base}/pages/{$id}", ['status' => 'draft'])->assertOk()->assertJsonPath('data.public_token', null);
    $this->getJson("/api/v1/public/pages/{$token}")->assertNotFound();
    $this->patchJson("{$this->base}/pages/{$id}", ['status' => 'published'])->assertJsonPath('data.public_token', null);
    $this->getJson("/api/v1/public/pages/{$token}")->assertNotFound();
});

it('stops sharing a page that is deleted', function () {
    $id = page(['status' => 'published'])['id'];
    $token = $this->patchJson("{$this->base}/pages/{$id}", ['public' => true])->json('data.public_token');

    $this->deleteJson("{$this->base}/pages/{$id}")->assertNoContent();

    $this->getJson("/api/v1/public/pages/{$token}")->assertNotFound();
    $this->getJson("{$this->base}/pages/{$id}")->assertNotFound();
    expect(Page::withoutGlobalScopes()->withTrashed()->find($id)->public_token)->toBeNull();
});

it('shows guests published pages only and lets them write nothing', function () {
    $draft = page(['title' => 'Draft'])['id'];
    $published = page(['title' => 'Published', 'status' => 'published'])['id'];
    $guest = addMember($this->workspace, WorkspaceRole::Guest);

    $this->actingAs($guest);
    $this->getJson("{$this->base}/pages")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Published');
    $this->getJson("{$this->base}/pages/{$draft}")->assertNotFound();
    $this->getJson("{$this->base}/pages/{$published}")->assertOk();
    $this->postJson("{$this->base}/pages", ['title' => 'x'])->assertForbidden();
    $this->patchJson("{$this->base}/pages/{$published}", ['title' => 'x'])->assertForbidden();
    $this->deleteJson("{$this->base}/pages/{$published}")->assertForbidden();
});

it('lets an agent write pages of its projects but not share or delete them', function () {
    $this->postJson("{$this->base}/projects", ['name' => 'Mobile', 'key' => 'MOB'])->assertCreated();
    $workspaceWide = page(['title' => 'Workspace rules', 'status' => 'published'])['id'];
    $mobile = page(['title' => 'Mobile notes', 'project' => 'MOB'])['id'];
    $web = page(['title' => 'Web notes', 'project' => 'WEB', 'status' => 'published'])['id'];

    $agent = User::factory()->agent($this->workspace, $this->user)->create();
    $webId = Project::withoutGlobalScopes()->where('key', 'WEB')->value('id');
    $token = $agent->createToken('web', ['read', 'write', "project:{$webId}"])->plainTextToken;
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin')->withToken($token);

    $this->getJson("{$this->base}/pages")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $web);
    $this->getJson("{$this->base}/pages/{$mobile}")->assertNotFound();
    $this->getJson("{$this->base}/pages/{$workspaceWide}")->assertNotFound();

    $this->patchJson("{$this->base}/pages/{$web}", ['body' => 'Updated by the agent'])->assertOk();
    $this->postJson("{$this->base}/pages", ['title' => 'Agent notes', 'project' => 'WEB'])->assertCreated();
    $this->postJson("{$this->base}/pages", ['title' => 'Elsewhere', 'project' => 'MOB'])->assertUnprocessable();

    $this->patchJson("{$this->base}/pages/{$web}", ['public' => true])->assertForbidden()->assertJsonPath('error.code', 'not_available_to_agents');
    $this->deleteJson("{$this->base}/pages/{$web}")->assertForbidden();
});

it('keeps pages inside their workspace', function () {
    $id = page()['id'];
    [$other, $stranger] = workspaceWith();

    $this->actingAs($stranger);
    $this->getJson("/api/v1/workspaces/{$other->slug}/pages")->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/workspaces/{$other->slug}/pages/{$id}")->assertNotFound();
    $this->getJson("{$this->base}/pages/{$id}")->assertNotFound();
});
