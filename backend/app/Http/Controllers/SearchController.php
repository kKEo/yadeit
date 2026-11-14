<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Page;
use App\Models\WorkItem;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One search box for everything written in a workspace: work items (title
 * and description), comments, and docs (title and text).
 *
 * Every word of the query must appear, in any order, ignoring case. The
 * matching is plain substring search so that it behaves the same on every
 * supported database; an exact item key such as WEB-12 always finds the item.
 */
class SearchController extends Controller
{
    private const TYPES = ['items', 'comments', 'pages'];

    private const MAX_WORDS = 6;

    /** q: the words to find. types: any of items, comments, pages (default all). project: a project key. limit: results per type, 1-20 (default 8). */
    public function __invoke(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $input = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'types' => ['sometimes', 'string'],
            'project' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $words = array_slice(array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($input['q']))) ?: [])), 0, self::MAX_WORDS);
        $types = isset($input['types'])
            ? array_values(array_intersect(self::TYPES, array_map('trim', explode(',', $input['types']))))
            : self::TYPES;
        $limit = (int) ($input['limit'] ?? 8);
        $project = isset($input['project']) ? strtoupper($input['project']) : null;

        validator(['types' => $types], ['types' => ['array', 'min:1']], ['types.min' => 'Pass any of: '.implode(', ', self::TYPES).'.'])->validate();

        $results = [];

        if (in_array('items', $types, true)) {
            $results = [...$results, ...$this->items($words, trim($input['q']), $project, $limit)];
        }

        if (in_array('comments', $types, true)) {
            $results = [...$results, ...$this->comments($words, $project, $limit)];
        }

        if (in_array('pages', $types, true)) {
            $results = [...$results, ...$this->pages($words, $project, $limit, $current)];
        }

        return response()->json(['data' => $results]);
    }

    /**
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function items(array $words, string $raw, ?string $project, int $limit): array
    {
        $query = WorkItem::query()->with(['project', 'state'])
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $text) => $this->allWords($text, $words, ['title', 'description']))
                ->orWhere(fn (Builder $byKey) => $byKey->whereItemKey($raw)));

        if ($project !== null) {
            $query->whereHas('project', fn (Builder $q) => $q->where('key', $project));
        }

        // Open work first, then the most recently touched.
        $items = $query->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('updated_at')->limit($limit * 3)->get();

        // A match in the title says more than one deep in the description.
        return $items
            ->sortBy(fn (WorkItem $item) => $this->contains($item->title, $words) || strcasecmp($item->key, $raw) === 0 ? 0 : 1)
            ->take($limit)
            ->map(fn (WorkItem $item) => [
                'type' => 'item',
                'key' => $item->key,
                'title' => $item->title,
                'state' => $item->state->summary(),
                'snippet' => $this->contains($item->title, $words) ? null : $this->snippet($item->description, $words),
            ])->values()->all();
    }

    /**
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function comments(array $words, ?string $project, int $limit): array
    {
        $query = Comment::query()->with(['author', 'item.project'])
            ->where(fn (Builder $text) => $this->allWords($text, $words, ['body']))
            // Through the item, so that deleted items and projects outside a token's reach are left out.
            ->whereHas('item', fn (Builder $item) => $project === null
                ? $item
                : $item->whereHas('project', fn (Builder $q) => $q->where('key', $project)));

        return $query->orderByDesc('id')->limit($limit)->get()->map(fn (Comment $comment) => [
            'type' => 'comment',
            'key' => $comment->item->key,
            'title' => $comment->item->title,
            'author' => $comment->author?->name,
            'snippet' => $this->snippet($comment->body, $words),
        ])->all();
    }

    /**
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function pages(array $words, ?string $project, int $limit, CurrentWorkspace $current): array
    {
        $query = Page::query()->with('project')->where(fn (Builder $text) => $this->allWords($text, $words, ['title', 'body']));

        // Guests read published pages only, here as everywhere.
        if (! $current->role()?->atLeast(WorkspaceRole::Member)) {
            $query->where('status', Page::PUBLISHED);
        }

        if ($project !== null) {
            $query->whereHas('project', fn (Builder $q) => $q->where('key', $project));
        }

        return $query->orderByDesc('updated_at')->limit($limit)->get()->map(fn (Page $page) => [
            'type' => 'page',
            'id' => $page->id,
            'title' => $page->title,
            'status' => $page->status,
            'project' => $page->project?->key,
            'snippet' => $this->contains($page->title, $words) ? null : $this->snippet($page->body, $words),
        ])->all();
    }

    /**
     * Each word must occur in at least one of the columns.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $words
     * @param  list<string>  $columns
     */
    private function allWords(Builder $query, array $words, array $columns): void
    {
        foreach ($words as $word) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';

            $query->where(function (Builder $any) use ($columns, $like, $query): void {
                foreach ($columns as $column) {
                    $any->orWhereRaw('LOWER('.$query->getModel()->qualifyColumn($column).') LIKE ?', [$like]);
                }
            });
        }
    }

    /** @param  list<string>  $words */
    private function contains(?string $text, array $words): bool
    {
        $text = mb_strtolower((string) $text);

        foreach ($words as $word) {
            if (! str_contains($text, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A short stretch of the text around the first word found.
     *
     * @param  list<string>  $words
     */
    private function snippet(?string $text, array $words): ?string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        if ($text === '') {
            return null;
        }

        $lower = mb_strtolower($text);
        $at = null;

        foreach ($words as $word) {
            $found = mb_strpos($lower, $word);

            if ($found !== false && ($at === null || $found < $at)) {
                $at = $found;
            }
        }

        $start = max(0, ($at ?? 0) - 60);

        return ($start > 0 ? '…' : '').Str::limit(mb_substr($text, $start), 160, '…');
    }
}
