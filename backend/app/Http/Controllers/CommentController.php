<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Services\WorkItemWriter;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Everyone in the workspace, guests included, may comment. */
class CommentController extends Controller
{
    public function __construct(private WorkItemWriter $writer) {}

    public function index(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $limit = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']])['limit'] ?? 50;

        $page = $item->comments()->with('author')->orderBy('id')->cursorPaginate((int) $limit);

        return response()->json([
            'data' => collect($page->items())->map(fn (Comment $c) => $c->summary()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request, Workspace $workspace, WorkItem $item): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:50000']]);

        $comment = $item->comments()->create([
            'workspace_id' => $item->workspace_id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $item->subscribe($request->user()->id);
        $item->recordActivity('commented', $request->user()->id, ['comment_id' => $comment->id]);

        // A person's reply is the input a paused item was waiting for.
        if ($item->needs_input && ! $request->user()->isAgent()) {
            $this->writer->inputProvided($item, $request->user());
        }

        return response()->json(['data' => $comment->load('author')->summary()], 201);
    }

    public function update(Request $request, Workspace $workspace, WorkItem $item, int $comment): JsonResponse
    {
        $model = $this->find($item, $comment);

        if ($model->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('Only the author can edit a comment.');
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:50000']]);
        $model->update(['body' => $data['body'], 'edited_at' => now()]);

        return response()->json(['data' => $model->load('author')->summary()]);
    }

    public function destroy(Request $request, Workspace $workspace, WorkItem $item, int $comment, CurrentWorkspace $current): Response
    {
        $model = $this->find($item, $comment);

        if ($model->user_id !== $request->user()->id) {
            $current->requireRole(WorkspaceRole::Admin);
        }

        $model->delete();

        return response()->noContent();
    }

    private function find(WorkItem $item, int $id): Comment
    {
        return $item->comments()->whereKey($id)->first() ?? throw new NotFoundHttpException('Comment not found.');
    }
}
