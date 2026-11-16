<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Files live on a private disk under generated names and are only ever
 * served through the authorised download route below.
 */
class AttachmentController extends Controller
{
    public const MAX_KILOBYTES = 20 * 1024;

    private const DISK = 'local';

    public function index(Workspace $workspace, WorkItem $item): JsonResponse
    {
        return response()->json(['data' => $item->attachments->map(fn (Attachment $a) => $a->summary())]);
    }

    public function store(Request $request, Workspace $workspace, WorkItem $item, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Member);
        $request->validate(['file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES]]);

        $file = $request->file('file');

        // The stored name is random and has no extension; the original name is kept only as a label.
        $path = $file->storeAs("attachments/{$workspace->id}/{$item->id}", (string) Str::uuid(), self::DISK);

        $attachment = $item->attachments()->create([
            'workspace_id' => $item->workspace_id,
            'uploaded_by' => $request->user()->id,
            'disk' => self::DISK,
            'path' => $path,
            // Kept as a label only; characters that break a download header or look like a path are replaced.
            'name' => Str::limit(trim((string) preg_replace('/[\x00-\x1f\x7f\\\\\/"]+/', '_', basename((string) $file->getClientOriginalName()))), 250, '') ?: 'file',
            // Detected from the bytes, never from the file name or what the client claims.
            'mime_type' => (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream',
            'size' => $file->getSize(),
        ]);

        $item->recordActivity('attachment_added', $request->user()->id, ['name' => $attachment->name]);

        return response()->json(['data' => $attachment->summary()], 201);
    }

    public function download(Workspace $workspace, WorkItem $item, int $attachment): StreamedResponse
    {
        $model = $this->find($item, $attachment);
        $disk = Storage::disk($model->disk);

        if (! $disk->exists($model->path)) {
            throw new NotFoundHttpException('The file is missing.');
        }

        // Only known image types may render in the browser; anything else is forced to download.
        $headers = ['Content-Type' => $model->isImage() ? $model->mime_type : 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'];

        return $model->isImage()
            ? $disk->response($model->path, $model->name, $headers)
            : $disk->download($model->path, $model->name, $headers);
    }

    public function destroy(Request $request, Workspace $workspace, WorkItem $item, int $attachment, CurrentWorkspace $current): Response
    {
        $current->requireRole(WorkspaceRole::Member);
        $model = $this->find($item, $attachment);

        // A picture drawn into the whiteboard would leave a hole there.
        if (in_array($model->id, $item->whiteboard?->attachmentIds() ?? [], true)) {
            throw new ApiException(409, 'in_use', 'This picture is on the whiteboard of the item. Remove it from the drawing first.');
        }

        Storage::disk($model->disk)->delete($model->path);
        $model->delete();
        $item->recordActivity('attachment_removed', $request->user()->id, ['name' => $model->name]);

        return response()->noContent();
    }

    private function find(WorkItem $item, int $id): Attachment
    {
        return $item->attachments()->whereKey($id)->first() ?? throw new NotFoundHttpException('Attachment not found.');
    }
}
