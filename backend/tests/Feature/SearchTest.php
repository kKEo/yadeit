<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $this->postJson("{$this->base}/projects", ['name' => 'Mobile', 'key' => 'MOB'])->assertCreated();

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Login fails with Safari', 'description' => 'The cookie is dropped.'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Dark mode', 'description' => "Colours first.\n\nThen check the login screen in Safari as well."])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'MOB', 'title' => 'Push notifications'])->assertCreated();
    $this->postJson("{$this->base}/items/WEB-2/comments", ['body' => 'I tried Safari 19 and the contrast is too low.'])->assertCreated();
    $this->postJson("{$this->base}/pages", ['title' => 'Browser support', 'body' => 'We support Safari, Firefox and Chrome.', 'status' => 'published'])->assertCreated();
    $this->postJson("{$this->base}/pages", ['title' => 'Safari quirks (draft)', 'project' => 'WEB'])->assertCreated();
});

function found(string $query): array
{
    return test()->getJson(test()->base.'/search?'.$query)->assertOk()->json('data');
}

it('finds items, comments and pages in one request', function () {
    $results = found('q=safari');

    expect(collect($results)->countBy('type')->all())->toBe(['item' => 2, 'comment' => 1, 'page' => 2]);

    $items = collect($results)->where('type', 'item')->values();
    // A title match comes before a match in the description, which gets a snippet.
    expect($items[0])->toMatchArray(['key' => 'WEB-1', 'title' => 'Login fails with Safari', 'snippet' => null])
        ->and($items[1]['key'])->toBe('WEB-2')
        ->and($items[1]['snippet'])->toContain('login screen in Safari');

    $comment = collect($results)->firstWhere('type', 'comment');
    expect($comment)->toMatchArray(['key' => 'WEB-2', 'title' => 'Dark mode', 'author' => $this->user->name])
        ->and($comment['snippet'])->toContain('Safari 19');

    expect(collect($results)->where('type', 'page')->pluck('title')->all())->toEqualCanonicalizing(['Browser support', 'Safari quirks (draft)']);
});

it('needs every word, in any order and any case', function () {
    expect(collect(found('q=SAFARI+login'))->where('type', 'item')->pluck('key')->all())->toBe(['WEB-1', 'WEB-2']);
    expect(found('q=safari+notifications'))->toBe([]);
    expect(collect(found('q=chrome+firefox'))->pluck('title')->all())->toBe(['Browser support']);
});

it('finds an item by its key', function () {
    expect(found('q=mob-1&types=items'))->toHaveCount(1)->and(found('q=mob-1&types=items')[0]['key'])->toBe('MOB-1');
});

it('narrows by type and project', function () {
    expect(collect(found('q=safari&types=pages'))->pluck('type')->unique()->all())->toBe(['page']);
    expect(collect(found('q=safari&project=web&types=pages'))->pluck('title')->all())->toBe(['Safari quirks (draft)']);
    expect(found('q=push&project=WEB'))->toBe([]);

    $this->getJson("{$this->base}/search?q=safari&types=people")->assertUnprocessable();
    $this->getJson("{$this->base}/search?q=s")->assertUnprocessable();
});

it('treats wildcard characters as ordinary text', function () {
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => '100% done_now'])->assertCreated();

    expect(found('q=100%25&types=items'))->toHaveCount(1);
    expect(found('q=done_now&types=items'))->toHaveCount(1);
    expect(found('q=%25%25&types=items'))->toBe([]);
});

it('leaves out deleted items and their comments', function () {
    $this->deleteJson("{$this->base}/items/WEB-2")->assertNoContent();

    $results = found('q=safari');
    expect(collect($results)->where('type', 'item')->pluck('key')->all())->toBe(['WEB-1'])
        ->and(collect($results)->where('type', 'comment'))->toBeEmpty();
});

it('hides drafts from guests', function () {
    $guest = addMember($this->workspace, WorkspaceRole::Guest);

    expect(collect($this->actingAs($guest)->getJson("{$this->base}/search?q=safari&types=pages")->json('data'))->pluck('title')->all())
        ->toBe(['Browser support']);
});

it('stays inside the projects of a limited token and inside the workspace', function () {
    $this->postJson("{$this->base}/items/MOB-1/comments", ['body' => 'Safari on iOS needs a permission prompt.'])->assertCreated();
    $agent = User::factory()->agent($this->workspace, $this->user)->create();
    $mobId = Project::withoutGlobalScopes()->where('key', 'MOB')->value('id');
    $token = $agent->createToken('mob', ['read', "project:{$mobId}"])->plainTextToken;
    [$other, $stranger] = workspaceWith();

    expect($this->actingAs($stranger)->getJson("/api/v1/workspaces/{$other->slug}/search?q=safari")->assertOk()->json('data'))->toBe([]);

    app('auth')->forgetGuards();
    $this->flushSession();
    $results = $this->withoutHeader('Origin')->withToken($token)->getJson("{$this->base}/search?q=safari")->assertOk()->json('data');

    // Only the comment on the Mobile item: no Website items, no workspace-wide or Website pages.
    expect($results)->toHaveCount(1)->and($results[0])->toMatchArray(['type' => 'comment', 'key' => 'MOB-1']);
});
