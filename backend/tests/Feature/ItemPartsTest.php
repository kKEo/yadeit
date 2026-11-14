<?php

use App\Enums\WorkspaceRole;
use App\Models\Attachment;
use App\Support\CurrentWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user)->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB']);
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'First'])->assertCreated();
    $this->item = "{$this->base}/items/WEB-1";
});

describe('comments', function () {
    it('adds, lists, edits and deletes comments', function () {
        $id = $this->postJson("{$this->item}/comments", ['body' => 'First **comment**'])
            ->assertCreated()
            ->assertJsonPath('data.author.id', $this->user->id)
            ->json('data.id');

        $this->getJson("{$this->item}/comments")->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'First **comment**');
        $this->getJson($this->item)->assertJsonPath('data.comment_count', 1);

        $this->patchJson("{$this->item}/comments/{$id}", ['body' => 'Edited'])
            ->assertOk()->assertJsonPath('data.edited_at', fn ($v) => $v !== null);

        $this->deleteJson("{$this->item}/comments/{$id}")->assertNoContent();
        $this->getJson("{$this->item}/comments")->assertJsonCount(0, 'data');
    });

    it('lets only the author edit, and only the author or an admin delete', function () {
        $id = $this->postJson("{$this->item}/comments", ['body' => 'Mine'])->json('data.id');
        $other = addMember($this->workspace);
        $admin = addMember($this->workspace, WorkspaceRole::Admin);

        $this->actingAs($other)->patchJson("{$this->item}/comments/{$id}", ['body' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("{$this->item}/comments/{$id}")->assertForbidden();
        $this->actingAs($admin)->patchJson("{$this->item}/comments/{$id}", ['body' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("{$this->item}/comments/{$id}")->assertNoContent();
    });

    it('subscribes the commenter and records the comment in the history', function () {
        $other = addMember($this->workspace);

        $this->actingAs($other)->getJson($this->item)->assertJsonPath('data.subscribed', false);
        $this->postJson("{$this->item}/comments", ['body' => 'Hi']);
        $this->getJson($this->item)->assertJsonPath('data.subscribed', true);
        $this->getJson("{$this->item}/activity")->assertJsonPath('data.0.event', 'commented');

        $this->deleteJson("{$this->item}/subscription")->assertNoContent();
        $this->getJson($this->item)->assertJsonPath('data.subscribed', false);
        $this->putJson("{$this->item}/subscription")->assertNoContent();
        $this->getJson($this->item)->assertJsonPath('data.subscribed', true);
    });

    it('cannot reach a comment through another item', function () {
        $id = $this->postJson("{$this->item}/comments", ['body' => 'On first'])->json('data.id');
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Second']);

        $this->patchJson("{$this->base}/items/WEB-2/comments/{$id}", ['body' => 'x'])->assertNotFound();
    });
});

it('manages the checklist and records ticks in the history', function () {
    $id = $this->postJson("{$this->item}/checklist", ['text' => 'Works on mobile'])->assertCreated()->json('data.id');

    $this->patchJson("{$this->item}/checklist/{$id}", ['is_done' => true])->assertOk()->assertJsonPath('data.is_done', true);
    $this->getJson($this->item)->assertJsonPath('data.checklist.0.text', 'Works on mobile')->assertJsonPath('data.checklist.0.is_done', true);
    $this->getJson("{$this->item}/activity")->assertJsonPath('data.0.event', 'checklist_checked');

    $this->deleteJson("{$this->item}/checklist/{$id}")->assertNoContent();
    $this->deleteJson("{$this->item}/checklist/{$id}")->assertNotFound();
});

it('adds web links and refuses dangerous URL schemes', function (string $url, int $status) {
    $this->postJson("{$this->item}/links", ['url' => $url, 'kind' => 'pr', 'title' => 'PR 12'])->assertStatus($status);
})->with([
    ['https://github.com/acme/web/pull/12', 201],
    ['javascript:alert(1)', 422],
    ['data:text/html,<script>alert(1)</script>', 422],
    ['file:///etc/passwd', 422],
]);

it('removes links', function () {
    $id = $this->postJson("{$this->item}/links", ['url' => 'https://example.com'])->json('data.id');

    $this->getJson($this->item)->assertJsonPath('data.links.0.kind', 'url');
    $this->deleteJson("{$this->item}/links/{$id}")->assertNoContent();
    $this->getJson($this->item)->assertJsonCount(0, 'data.links');
});

it('relates items and shows the relation from both sides', function () {
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Second']);

    $id = $this->postJson("{$this->item}/relations", ['type' => 'blocks', 'item' => 'web-2'])->assertCreated()->json('data.id');
    // Repeating it does not create a duplicate.
    $this->postJson("{$this->item}/relations", ['type' => 'blocks', 'item' => 'WEB-2'])->assertJsonPath('data.id', $id);

    $this->getJson($this->item)
        ->assertJsonCount(1, 'data.relations')
        ->assertJsonPath('data.relations.0.type', 'blocks')
        ->assertJsonPath('data.relations.0.item.key', 'WEB-2');
    $this->getJson("{$this->base}/items/WEB-2")
        ->assertJsonPath('data.relations.0.type', 'blocked_by')
        ->assertJsonPath('data.relations.0.item.key', 'WEB-1');

    $this->postJson("{$this->item}/relations", ['type' => 'blocks', 'item' => 'WEB-1'])->assertUnprocessable();
    $this->postJson("{$this->item}/relations", ['type' => 'blocks', 'item' => 'WEB-99'])->assertUnprocessable();

    $this->deleteJson("{$this->base}/items/WEB-2/relations/{$id}")->assertNoContent();
    $this->getJson($this->item)->assertJsonCount(0, 'data.relations');
});

describe('attachments', function () {
    beforeEach(fn () => Storage::fake('local'));

    it('stores an upload privately under a generated name and serves it back', function () {
        $file = UploadedFile::fake()->image('screenshot.png', 20, 20);

        $id = $this->post("{$this->item}/attachments", ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'screenshot.png')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.is_image', true)
            ->json('data.id');

        $stored = app(CurrentWorkspace::class)->runUnscoped(fn () => Attachment::findOrFail($id));
        expect($stored->path)->not->toContain('screenshot')->not->toContain('.png');
        Storage::disk('local')->assertExists($stored->path);

        $this->get("{$this->item}/attachments/{$id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->getJson($this->item)->assertJsonPath('data.attachments.0.name', 'screenshot.png');
    });

    it('forces anything that is not a known image type to download', function () {
        $file = UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>');
        $id = $this->post("{$this->item}/attachments", ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $response = $this->get("{$this->item}/attachments/{$id}/download")->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment');
    });

    it('does not trust the file extension for the type', function () {
        $file = UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";');

        $this->post("{$this->item}/attachments", ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.is_image', false);
    });

    it('limits the size and requires a file', function () {
        $tooBig = UploadedFile::fake()->create('big.bin', 20 * 1024 + 1);

        $this->post("{$this->item}/attachments", ['file' => $tooBig], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson("{$this->item}/attachments", [])->assertUnprocessable();
    });

    it('keeps files away from guests\' uploads, other items and other workspaces', function () {
        $id = $this->post("{$this->item}/attachments", ['file' => UploadedFile::fake()->create('a.txt', 1)], ['Accept' => 'application/json'])->json('data.id');
        $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Second']);

        $this->get("{$this->base}/items/WEB-2/attachments/{$id}/download", ['Accept' => 'application/json'])->assertNotFound();

        $guest = addMember($this->workspace, WorkspaceRole::Guest);
        $this->actingAs($guest)->get("{$this->item}/attachments/{$id}/download")->assertOk();
        $this->post("{$this->item}/attachments", ['file' => UploadedFile::fake()->create('b.txt', 1)], ['Accept' => 'application/json'])->assertForbidden();

        [, $outsider] = workspaceWith();
        $this->actingAs($outsider)->get("{$this->item}/attachments/{$id}/download", ['Accept' => 'application/json'])->assertNotFound();
    });

    it('deletes the file together with the record', function () {
        $id = $this->post("{$this->item}/attachments", ['file' => UploadedFile::fake()->create('a.txt', 1)], ['Accept' => 'application/json'])->json('data.id');
        $path = app(CurrentWorkspace::class)->runUnscoped(fn () => Attachment::findOrFail($id))->path;

        $this->deleteJson("{$this->item}/attachments/{$id}")->assertNoContent();

        Storage::disk('local')->assertMissing($path);
        $this->getJson("{$this->item}/attachments")->assertJsonCount(0, 'data');
    });
});
