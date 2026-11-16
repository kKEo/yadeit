<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\ItemWhiteboard;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The optional whiteboard of a work item: a drawing that describes the work,
 * next to the text description. The item itself only says whether it has one
 * and which words are on it; the drawing is read here.
 */
class WhiteboardController extends Controller
{
    /** Largest scene kept, as JSON. */
    private const SCENE_BYTES = 2_000_000;

    public function __construct(private CurrentWorkspace $current) {}

    /** The drawing (an Excalidraw scene), a picture of it as SVG, and the words on it. 404 when the item has none. */
    public function show(Workspace $workspace, WorkItem $item): JsonResponse
    {
        $board = $item->whiteboard()->with('editor')->first() ?? throw new NotFoundHttpException('This item has no whiteboard.');

        return response()->json(['data' => $board->summary()]);
    }

    /**
     * Creates or replaces the whiteboard. Send the version you last saw as
     * `expected_version` to avoid overwriting someone else's drawing; a
     * mismatch answers 412.
     *
     * A picture on the board is an attachment of the item: upload it first
     * (POST items/{key}/attachments; PNG, JPEG, GIF or WebP), then name it in
     * `scene.files` under the `fileId` of its image element, as
     * `{"<fileId>": {"attachment_id": 12}}`.
     */
    public function update(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Member);

        $data = $request->validate([
            'scene' => ['required', 'array'],
            'scene.elements' => ['required', 'array', 'min:1', 'max:5000'],
            'scene.elements.*' => ['array'],
            'scene.appState' => ['sometimes', 'array'],
            'scene.appState.viewBackgroundColor' => ['sometimes', 'string', 'max:30'],
            'scene.files' => ['sometimes', 'array', 'max:200'],
            'scene.files.*.attachment_id' => ['sometimes', 'integer'],
            'preview_svg' => ['required', 'string', 'max:2000000', 'starts_with:<svg'],
            'expected_version' => ['sometimes', 'nullable', 'integer'],
        ]);

        $elements = array_values(array_filter(
            (array) $request->input('scene.elements'),
            fn (mixed $element) => is_array($element) && ($element['isDeleted'] ?? false) !== true,
        ));

        if ($elements === []) {
            throw ValidationException::withMessages(['scene.elements' => ['The whiteboard is empty. Remove it instead of saving it empty.']]);
        }

        // Only what a drawing needs is kept: the elements, the background and where its pictures are; not the editor's own state.
        $scene = [
            'type' => 'excalidraw',
            'version' => 2,
            'elements' => $elements,
            'appState' => ['viewBackgroundColor' => $data['scene']['appState']['viewBackgroundColor'] ?? '#ffffff'],
        ];

        if (($files = $this->pictures($item, $elements, (array) $request->input('scene.files', []))) !== []) {
            $scene['files'] = $files;
        }

        if (strlen((string) json_encode($scene)) > self::SCENE_BYTES) {
            throw ValidationException::withMessages(['scene' => ['This whiteboard is too large to save. Split it over several items.']]);
        }

        $user = $request->user();
        $expected = $data['expected_version'] ?? null;

        $board = DB::transaction(function () use ($item, $scene, $data, $user, $expected): ItemWhiteboard {
            // The item row is the lock, so two people saving at once are handled one after the other.
            $item = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $board = $item->whiteboard()->first();
            $current = $board->version ?? 0;

            if ($expected !== null && $expected !== $current) {
                throw new ApiException(412, 'version_mismatch',
                    'This whiteboard was changed by someone else. Load it again and retry.',
                    ['current_version' => $current, 'expected_version' => $expected]);
            }

            $values = [
                'scene' => $scene,
                'preview_svg' => $data['preview_svg'],
                'text' => ItemWhiteboard::textOf($scene['elements']),
                'version' => $current + 1,
                'updated_by' => $user->id,
            ];

            $board
                ? $board->update($values)
                : $board = $item->whiteboard()->create([...$values, 'workspace_id' => $item->workspace_id]);

            $item->subscribe($user->id);
            $item->recordActivity($current === 0 ? 'whiteboard_added' : 'whiteboard_updated', $user->id);

            return $board;
        });

        return response()->json(['data' => $board->load('editor')->summary()]);
    }

    /**
     * The pictures the drawing uses, by the file id of their image element.
     * Each one has to be an image attached to this very item; files that no
     * element uses any more are dropped.
     *
     * @param  list<array<string, mixed>>  $elements
     * @param  array<int|string, mixed>  $files
     * @return array<string, array{attachment_id: int, mime_type: string}>
     */
    private function pictures(WorkItem $item, array $elements, array $files): array
    {
        $used = collect($elements)
            ->filter(fn (array $element) => ($element['type'] ?? null) === 'image')
            ->map(fn (array $element) => is_scalar($element['fileId'] ?? null) ? (string) $element['fileId'] : '')
            ->unique()->values();

        if ($used->isEmpty()) {
            return [];
        }

        $attachments = $item->attachments()->get()->keyBy('id');
        $pictures = [];

        foreach ($used as $fileId) {
            $attachment = $attachments->get((int) data_get($files, [$fileId, 'attachment_id'], 0));

            if (! $attachment instanceof Attachment || ! $attachment->isImage()) {
                throw ValidationException::withMessages(['scene.files' => [
                    "Every picture on the whiteboard has to be an image attached to {$item->key} (PNG, JPEG, GIF or WebP). "
                    .'Upload it to the item\'s attachments, then name it in scene.files as {"<fileId>": {"attachment_id": <id>}}.',
                ]]);
            }

            $pictures[$fileId] = ['attachment_id' => $attachment->id, 'mime_type' => $attachment->mime_type];
        }

        return $pictures;
    }

    /** Pictures that were on the board stay attached to the item. */
    public function destroy(Request $request, Workspace $workspace, WorkItem $item): Response
    {
        $this->current->requireRole(WorkspaceRole::Member);

        $board = $item->whiteboard()->first() ?? throw new NotFoundHttpException('This item has no whiteboard.');

        DB::transaction(function () use ($board, $item, $request): void {
            $board->delete();
            $item->recordActivity('whiteboard_removed', $request->user()->id);
        });

        return response()->noContent();
    }
}
