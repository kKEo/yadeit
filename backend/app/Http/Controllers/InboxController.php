<?php

namespace App\Http\Controllers;

use App\Models\InboxItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A person's notifications in one workspace. Snoozed entries stay hidden until their time comes. */
class InboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $page = $this->mine($request)
            ->where(fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->when($request->boolean('unread'), fn (Builder $q) => $q->whereNull('read_at'))
            ->with(['workItem.project', 'actor'])
            ->orderByDesc('id')
            ->cursorPaginate((int) ($input['limit'] ?? 30));

        return response()->json([
            'data' => collect($page->items())->map(fn (InboxItem $i) => $i->summary()),
            'next_cursor' => $page->nextCursor()?->encode(),
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    public function update(Request $request, Workspace $workspace, int $entry): JsonResponse
    {
        $model = $this->mine($request)->whereKey($entry)->first() ?? throw new NotFoundHttpException('Notification not found.');

        $data = $request->validate([
            'read' => ['sometimes', 'boolean'],
            'snoozed_until' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        if (array_key_exists('read', $data)) {
            $model->read_at = $data['read'] ? ($model->read_at ?? now()) : null;
        }

        if (array_key_exists('snoozed_until', $data)) {
            $model->snoozed_until = $data['snoozed_until'];
        }

        $model->save();

        return response()->json(['data' => $model->load(['workItem.project', 'actor'])->summary(), 'unread_count' => $this->unreadCount($request)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->mine($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function destroy(Request $request, Workspace $workspace, int $entry): Response
    {
        $deleted = $this->mine($request)->whereKey($entry)->delete();

        return $deleted ? response()->noContent() : throw new NotFoundHttpException('Notification not found.');
    }

    /** @return Builder<InboxItem> */
    private function mine(Request $request): Builder
    {
        return InboxItem::query()->where('user_id', $request->user()->id);
    }

    private function unreadCount(Request $request): int
    {
        return $this->mine($request)
            ->whereNull('read_at')
            ->where(fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->count();
    }
}
