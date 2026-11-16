<?php

use App\Enums\WorkspaceRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    [$this->workspace, $this->user] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->user)->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB']);
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Checkout flow', 'description' => 'Three steps.'])->assertCreated();
    $this->item = "{$this->base}/items/WEB-1";
    $this->board = "{$this->item}/whiteboard";

    $this->drawing = fn (?array $elements = null, array $more = []) => [
        'scene' => [
            'elements' => $elements ?? [
                ['id' => 'a', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 120, 'height' => 60],
                ['id' => 'b', 'type' => 'text', 'text' => 'Cart', 'containerId' => 'a'],
                ['id' => 'c', 'type' => 'text', 'text' => 'Old idea', 'isDeleted' => true],
            ],
            'appState' => ['viewBackgroundColor' => '#fdf8f6', 'zoom' => ['value' => 3], 'collaborators' => ['x' => 1]],
            'files' => ['f1' => ['dataURL' => 'data:image/png;base64,AAAA']],
        ],
        'preview_svg' => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="120" height="60"/></svg>',
        ...$more,
    ];
});

it('describes an item with a whiteboard next to its text', function () {
    $this->getJson($this->item)->assertJsonPath('data.whiteboard', null);
    $this->getJson($this->board)->assertNotFound();

    $this->putJson($this->board, ($this->drawing)())
        ->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.text', 'Cart')
        ->assertJsonPath('data.updated_by.id', $this->user->id)
        ->assertJsonPath('data.scene.appState', ['viewBackgroundColor' => '#fdf8f6'])
        // Erased elements, the editor's own state and embedded files are not kept.
        ->assertJsonCount(2, 'data.scene.elements')
        ->assertJsonMissingPath('data.scene.files');

    $this->getJson($this->board)->assertOk()->assertJsonPath('data.preview_svg', fn (string $svg) => str_starts_with($svg, '<svg'));

    // The item says it has one and what is written on it, without carrying the drawing; its text is untouched.
    $this->getJson($this->item)
        ->assertJsonPath('data.description', 'Three steps.')
        ->assertJsonPath('data.whiteboard.version', 1)
        ->assertJsonPath('data.whiteboard.text', 'Cart')
        ->assertJsonMissingPath('data.whiteboard.scene');
    $this->getJson("{$this->item}/activity")->assertJsonPath('data.0.event', 'whiteboard_added');
});

it('replaces the drawing and refuses to overwrite a change it has not seen', function () {
    $this->putJson($this->board, ($this->drawing)())->assertOk();

    $next = [['id' => 'd', 'type' => 'text', 'text' => "Pay\nnow"], ['id' => 'e', 'type' => 'text', 'text' => 'Done']];

    $this->putJson($this->board, ($this->drawing)($next, ['expected_version' => 1]))
        ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.text', "Pay\nnow\nDone");
    $this->getJson("{$this->item}/activity")->assertJsonPath('data.0.event', 'whiteboard_updated');

    $this->putJson($this->board, ($this->drawing)(null, ['expected_version' => 1]))
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'version_mismatch')
        ->assertJsonPath('error.details.current_version', 2);
    // Starting a board where someone else already did is the same conflict.
    $this->putJson($this->board, ($this->drawing)(null, ['expected_version' => 0]))->assertStatus(412);
    $this->getJson($this->board)->assertJsonPath('data.version', 2);
});

it('removes the whiteboard and leaves the item as it was', function () {
    $this->putJson($this->board, ($this->drawing)())->assertOk();

    $this->deleteJson($this->board)->assertNoContent();
    $this->deleteJson($this->board)->assertNotFound();
    $this->getJson($this->item)->assertJsonPath('data.whiteboard', null)->assertJsonPath('data.description', 'Three steps.');
    $this->getJson("{$this->item}/activity")->assertJsonPath('data.0.event', 'whiteboard_removed');
});

it('refuses drawings it cannot keep', function () {
    $this->putJson($this->board, ($this->drawing)([['id' => 'x', 'type' => 'text', 'text' => 'Gone', 'isDeleted' => true]]))
        ->assertUnprocessable()->assertJsonPath('error.details.fields', fn (array $fields) => isset($fields['scene.elements']));
    // A picture that is not an attachment of the item: the embedded file data is not a way in.
    $this->putJson($this->board, ($this->drawing)([['id' => 'i', 'type' => 'image', 'fileId' => 'f1']]))->assertUnprocessable();
    $this->putJson($this->board, ($this->drawing)(null, ['preview_svg' => '<script>alert(1)</script>']))->assertUnprocessable();
    $this->putJson($this->board, ['preview_svg' => '<svg/>'])->assertUnprocessable();
    $this->putJson($this->board, ($this->drawing)([['id' => 'big', 'type' => 'text', 'text' => str_repeat('a', 2_100_000)]]))->assertUnprocessable();

    $this->getJson($this->board)->assertNotFound();
});

it('lets a guest look but not draw, and keeps boards inside their workspace', function () {
    $this->putJson($this->board, ($this->drawing)())->assertOk();

    $guest = addMember($this->workspace, WorkspaceRole::Guest);
    $this->actingAs($guest)->getJson($this->board)->assertOk();
    $this->putJson($this->board, ($this->drawing)())->assertForbidden();
    $this->deleteJson($this->board)->assertForbidden();

    [, $stranger] = workspaceWith(WorkspaceRole::Owner);
    $this->actingAs($stranger)->getJson($this->board)->assertStatus(404);
});

it('goes away with its item', function () {
    $this->putJson($this->board, ($this->drawing)())->assertOk();
    $this->deleteJson($this->item)->assertNoContent();

    $this->getJson($this->board)->assertNotFound();
});

it('keeps the pictures of a drawing as attachments of the item', function () {
    Storage::fake('local');
    $upload = fn (string $item, UploadedFile $file) => $this->post("{$item}/attachments", ['file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    $picture = $upload($this->item, UploadedFile::fake()->image('mockup.png', 20, 20));
    $notes = $upload($this->item, UploadedFile::fake()->createWithContent('notes.txt', 'plain text'));
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Second'])->assertCreated();
    $elsewhere = $upload("{$this->base}/items/WEB-2", UploadedFile::fake()->image('other.png', 20, 20));

    $with = fn (int $attachment) => [
        'scene' => [
            'elements' => [['id' => 'i', 'type' => 'image', 'fileId' => 'f1'], ['id' => 't', 'type' => 'text', 'text' => 'Mock-up']],
            'files' => ['f1' => ['attachment_id' => $attachment, 'dataURL' => 'data:image/png;base64,AAAA'], 'unused' => ['attachment_id' => $attachment]],
        ],
        'preview_svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    ];

    // Only an image attached to this very item will do.
    $this->putJson($this->board, $with($notes))->assertUnprocessable();
    $this->putJson($this->board, $with($elsewhere))->assertUnprocessable();
    $this->putJson($this->board, $with(999999))->assertUnprocessable();

    $this->putJson($this->board, $with($picture))
        ->assertOk()
        ->assertJsonPath('data.scene.files', ['f1' => ['attachment_id' => $picture, 'mime_type' => 'image/png']]);
    $this->getJson($this->item)->assertJsonCount(2, 'data.attachments');

    // While it is on the drawing the attachment cannot be deleted; afterwards it can.
    $this->deleteJson("{$this->item}/attachments/{$picture}")->assertStatus(409)->assertJsonPath('error.code', 'in_use');
    $this->deleteJson("{$this->item}/attachments/{$notes}")->assertNoContent();

    $this->putJson($this->board, ($this->drawing)())->assertOk()->assertJsonMissingPath('data.scene.files');
    $this->deleteJson("{$this->item}/attachments/{$picture}")->assertNoContent();
});

it('leaves the pictures attached when the whiteboard is removed', function () {
    Storage::fake('local');
    $picture = $this->post("{$this->item}/attachments", ['file' => UploadedFile::fake()->image('mockup.png', 20, 20)], ['Accept' => 'application/json'])->json('data.id');

    $this->putJson($this->board, [
        'scene' => ['elements' => [['id' => 'i', 'type' => 'image', 'fileId' => 'f1']], 'files' => ['f1' => ['attachment_id' => $picture]]],
        'preview_svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    ])->assertOk();
    $this->deleteJson($this->board)->assertNoContent();

    $this->getJson($this->item)->assertJsonPath('data.attachments.0.id', $picture);
    $this->deleteJson("{$this->item}/attachments/{$picture}")->assertNoContent();
});
