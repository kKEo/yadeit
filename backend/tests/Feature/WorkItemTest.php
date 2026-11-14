<?php

use App\Enums\WorkspaceRole;
use App\Models\WorkItem;
use App\Support\CurrentWorkspace;

beforeEach(function () {
    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user)->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
});

function makeItem(array $data = []): array
{
    return test()->postJson(test()->base.'/items', ['project' => 'WEB', 'title' => 'An item', ...$data])
        ->assertCreated()->json('data');
}

it('creates items with sequential keys and sensible defaults', function () {
    $first = makeItem(['title' => 'First']);
    $second = makeItem(['title' => 'Second']);

    expect($first['key'])->toBe('WEB-1')
        ->and($second['key'])->toBe('WEB-2')
        ->and($first['state']['name'])->toBe('Todo')
        ->and($first['priority'])->toBe('none')
        ->and($first['kind'])->toBe('task')
        ->and($first['version'])->toBe(1)
        ->and($first['subscribed'])->toBeTrue();
});

it('never reuses a key after an item is deleted', function () {
    makeItem();
    $this->deleteJson("{$this->base}/items/WEB-1")->assertNoContent();
    $this->getJson("{$this->base}/items/WEB-1")->assertNotFound();

    expect(makeItem()['key'])->toBe('WEB-2');
});

it('accepts references the way people write them', function () {
    $this->postJson("{$this->base}/projects/WEB/labels", ['name' => 'frontend']);
    $parent = makeItem(['title' => 'Parent']);

    $item = makeItem([
        'state' => 'in progress', 'priority' => 'high', 'kind' => 'bug', 'assignee' => 'me',
        'labels' => ['Frontend'], 'parent' => $parent['key'], 'due_date' => '2027-01-31',
        'description' => "# Heading\n\nSome *markdown*.",
    ]);

    expect($item['state']['group'])->toBe('started')
        ->and($item['priority'])->toBe('high')
        ->and($item['kind'])->toBe('bug')
        ->and($item['assignee']['id'])->toBe($this->user->id)
        ->and($item['labels'][0]['name'])->toBe('frontend')
        ->and($item['parent'])->toBe('WEB-1')
        ->and($item['due_date'])->toBe('2027-01-31')
        ->and($item['description'])->toContain('*markdown*');

    $this->getJson("{$this->base}/items/WEB-1")->assertJsonPath('data.children.0.key', 'WEB-2');
});

it('explains unknown references with the valid choices', function (array $data, string $field, string $hint) {
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'x', ...$data])
        ->assertUnprocessable()
        ->assertJsonPath("error.details.fields.{$field}.0", fn (string $message) => str_contains($message, $hint));
})->with([
    'state' => [['state' => 'Nope'], 'state', 'In progress'],
    'label' => [['labels' => ['ghost']], 'labels', 'Unknown label'],
    'assignee' => [['assignee' => 999999], 'assignee', 'member'],
    'parent' => [['parent' => 'WEB-999'], 'parent', 'item key'],
    'project' => [['project' => 'NOPE'], 'project', 'WEB'],
]);

it('refuses an assignee from outside the workspace', function () {
    [, $outsider] = workspaceWith();

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'x', 'assignee' => $outsider->id])
        ->assertUnprocessable();
});

it('updates fields, bumps the version and records what changed', function () {
    makeItem(['title' => 'Old title']);

    $this->patchJson("{$this->base}/items/WEB-1", ['title' => 'New title', 'state' => 'Done', 'priority' => 'urgent'])
        ->assertOk()
        ->assertHeader('ETag', '"2"')
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.state.group', 'completed')
        ->assertJsonPath('data.completed_at', fn ($value) => $value !== null);

    $activity = $this->getJson("{$this->base}/items/WEB-1/activity")->assertOk()->json('data');

    expect($activity[0]['event'])->toBe('updated')
        ->and($activity[0]['actor']['id'])->toBe($this->user->id)
        ->and($activity[0]['changes']['title'])->toBe(['from' => 'Old title', 'to' => 'New title'])
        ->and($activity[0]['changes']['state'])->toBe(['from' => 'Todo', 'to' => 'Done'])
        ->and($activity[0]['changes']['priority'])->toBe(['from' => 'none', 'to' => 'urgent'])
        ->and($activity[1]['event'])->toBe('created');

    // Reopening clears the completion time.
    $this->patchJson("{$this->base}/items/WEB-1", ['state' => 'Todo'])->assertJsonPath('data.completed_at', null);
});

it('does not bump the version or add history when nothing changed', function () {
    makeItem(['title' => 'Same']);

    $this->patchJson("{$this->base}/items/WEB-1", ['title' => 'Same', 'priority' => 'none'])
        ->assertOk()->assertJsonPath('data.version', 1);

    $this->getJson("{$this->base}/items/WEB-1/activity")->assertJsonCount(1, 'data');
});

it('rejects an update based on a stale version', function () {
    makeItem();
    $this->patchJson("{$this->base}/items/WEB-1", ['title' => 'Changed elsewhere'])->assertOk();

    $this->patchJson("{$this->base}/items/WEB-1", ['title' => 'Mine', 'expected_version' => 1])
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'version_mismatch')
        ->assertJsonPath('error.details.current_version', 2);

    $this->withHeader('If-Match', '"1"')->patchJson("{$this->base}/items/WEB-1", ['title' => 'Mine'])->assertStatus(412);
    $this->withHeader('If-Match', '"2"')->patchJson("{$this->base}/items/WEB-1", ['title' => 'Mine'])->assertOk();
});

it('refuses a parent loop', function () {
    makeItem();
    makeItem(['parent' => 'WEB-1']);

    $this->patchJson("{$this->base}/items/WEB-1", ['parent' => 'WEB-2'])->assertUnprocessable();
    $this->patchJson("{$this->base}/items/WEB-1", ['parent' => 'WEB-1'])->assertUnprocessable();
});

it('replays a create that is retried with the same idempotency key', function () {
    $payload = ['project' => 'WEB', 'title' => 'Only once'];

    $first = $this->withHeader('Idempotency-Key', 'abc-123')->postJson("{$this->base}/items", $payload)->assertCreated();
    $second = $this->withHeader('Idempotency-Key', 'abc-123')->postJson("{$this->base}/items", $payload)
        ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json('data.key'))->toBe($first->json('data.key'));
    expect(app(CurrentWorkspace::class)->runUnscoped(fn () => WorkItem::count()))->toBe(1);

    $this->withHeader('Idempotency-Key', 'abc-123')->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Different'])
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
});

describe('listing', function () {
    beforeEach(function () {
        $this->other = addMember($this->workspace);
        makeItem(['title' => 'Fix login bug', 'kind' => 'bug', 'priority' => 'urgent', 'assignee' => 'me']);
        makeItem(['title' => 'Design the header', 'kind' => 'design', 'state' => 'In progress', 'assignee' => $this->other->id]);
        makeItem(['title' => 'Write docs', 'state' => 'Done', 'priority' => 'low']);
    });

    it('returns compact rows without descriptions by default', function () {
        $rows = $this->getJson("{$this->base}/items")->assertOk()->assertJsonCount(3, 'data')->json('data');

        expect($rows[0])->toHaveKeys(['key', 'title', 'state', 'priority', 'assignee', 'version'])
            ->not->toHaveKey('description');
    });

    it('filters', function (string $query, array $expectedKeys) {
        $keys = collect($this->getJson("{$this->base}/items?sort=sequence&{$query}")->assertOk()->json('data'))->pluck('key')->all();

        expect($keys)->toBe($expectedKeys);
    })->with([
        'by state group' => ['state_group=started', ['WEB-2']],
        'by state name' => ['state=Done', ['WEB-3']],
        'open only' => ['open=1', ['WEB-1', 'WEB-2']],
        'by priority' => ['priority=urgent', ['WEB-1']],
        'by kind' => ['kind=design', ['WEB-2']],
        'assigned to me' => ['assignee=me', ['WEB-1']],
        'unassigned' => ['assignee=none', ['WEB-3']],
        'by text' => ['q=LOGIN', ['WEB-1']],
        'by exact key' => ['q=web-3', ['WEB-3']],
        'by project' => ['project=web', ['WEB-1', 'WEB-2', 'WEB-3']],
        'by unknown project' => ['project=nope', []],
    ]);

    it('treats LIKE wildcards in the search text literally', function () {
        $this->getJson("{$this->base}/items?q=%25")->assertOk()->assertJsonCount(0, 'data');
    });

    it('sorts by priority and rejects unknown sort fields', function () {
        $keys = collect($this->getJson("{$this->base}/items?sort=-priority")->json('data'))->pluck('key')->all();
        expect($keys[0])->toBe('WEB-1');

        $this->getJson("{$this->base}/items?sort=password")->assertUnprocessable();
    });

    it('pages with a cursor', function () {
        $first = $this->getJson("{$this->base}/items?sort=sequence&limit=2")->assertOk()->assertJsonCount(2, 'data');
        $cursor = $first->json('next_cursor');
        expect($cursor)->not->toBeNull();

        $second = $this->getJson("{$this->base}/items?sort=sequence&limit=2&cursor={$cursor}")->assertJsonCount(1, 'data');
        expect($second->json('data.0.key'))->toBe('WEB-3')->and($second->json('next_cursor'))->toBeNull();
    });

    it('shapes the response with fields and expand', function () {
        $row = $this->getJson("{$this->base}/items?fields=title,priority&expand=description&limit=1")->json('data.0');
        expect(array_keys($row))->toEqualCanonicalizing(['key', 'title', 'priority']);

        $expanded = $this->getJson("{$this->base}/items?expand=description,checklist&limit=1")->json('data.0');
        expect($expanded)->toHaveKeys(['description', 'checklist']);
    });

    it('sends rate limit headers', function () {
        $this->getJson("{$this->base}/items")->assertHeader('X-RateLimit-Limit', 300)->assertHeader('X-RateLimit-Remaining');
    });
});

describe('access', function () {
    it('lets guests read and comment but not change items', function () {
        makeItem();
        $guest = addMember($this->workspace, WorkspaceRole::Guest);

        $this->actingAs($guest)->getJson("{$this->base}/items/WEB-1")->assertOk();
        $this->postJson("{$this->base}/items/WEB-1/comments", ['body' => 'Looks good'])->assertCreated();
        $this->patchJson("{$this->base}/items/WEB-1", ['title' => 'x'])->assertForbidden();
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'x'])->assertForbidden();
        $this->deleteJson("{$this->base}/items/WEB-1")->assertForbidden();
    });

    it('hides items of other workspaces even when the key matches', function () {
        makeItem(['title' => 'Mine']);
        [$other, $outsider] = workspaceWith();
        $otherBase = "/api/v1/workspaces/{$other->slug}";
        $this->actingAs($outsider)->postJson("{$otherBase}/projects", ['name' => 'Theirs', 'key' => 'WEB']);

        $this->getJson("{$otherBase}/items/WEB-1")->assertNotFound();
        $this->getJson("{$otherBase}/items")->assertJsonCount(0, 'data');
        $this->getJson("{$this->base}/items/WEB-1")->assertNotFound();

        $this->postJson("{$otherBase}/items", ['project' => 'WEB', 'title' => 'Theirs'])->assertJsonPath('data.key', 'WEB-1');
        $this->getJson("{$otherBase}/items/WEB-1")->assertJsonPath('data.title', 'Theirs');
        $this->postJson("{$otherBase}/items", ['project' => 'WEB', 'title' => 'x', 'parent' => 'WEB-1'])->assertCreated();
    });

    it('answers 404 for malformed keys', function (string $key) {
        $this->getJson("{$this->base}/items/{$key}")->assertNotFound();
    })->with(['WEB', '12', 'WEB-abc', 'WEB-1-2', "WEB-1'--"]);
});
