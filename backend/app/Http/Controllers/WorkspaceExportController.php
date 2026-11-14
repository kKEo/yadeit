<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Feedback;
use App\Models\Page;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The whole workspace as one JSON file, for its owners: members, projects with
 * their workflow, items with comments and checklists, pages and feedback.
 * Attachments are listed by name; the files themselves are not included.
 */
class WorkspaceExportController extends Controller
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __invoke(Workspace $workspace, CurrentWorkspace $current): StreamedResponse
    {
        $current->requireRole(WorkspaceRole::Owner);

        return response()->streamDownload(function () use ($workspace): void {
            echo '{"exported_at":'.json_encode(now()->toIso8601String());
            echo ',"workspace":'.json_encode(['name' => $workspace->name, 'slug' => $workspace->slug, 'plan' => $workspace->plan, 'created_at' => $workspace->created_at], self::FLAGS);
            echo ',"members":'.json_encode($this->members($workspace), self::FLAGS);
            echo ',"projects":'.json_encode($this->projects(), self::FLAGS);

            // Items can be many: they leave in pieces so the whole export never sits in memory.
            echo ',"items":[';
            $first = true;
            WorkItem::query()
                ->with(['project', 'state', 'assignee', 'delegate', 'creator', 'parent.project', 'labels', 'cycle', 'checklist', 'links', 'outgoingRelations.related.project', 'comments.author', 'attachments'])
                ->orderBy('id')
                ->chunkById(200, function ($items) use (&$first): void {
                    foreach ($items as $item) {
                        echo ($first ? '' : ',').json_encode($this->item($item), self::FLAGS);
                        $first = false;
                    }
                    flush();
                });
            echo ']';

            echo ',"pages":'.json_encode(Page::query()->with(['project', 'author'])->orderBy('id')->get()->map(fn (Page $page) => [
                'title' => $page->title,
                'body' => $page->body,
                'status' => $page->status,
                'project' => $page->project?->key,
                'author' => $page->author?->name,
                'created_at' => $page->created_at,
                'updated_at' => $page->updated_at,
            ]), self::FLAGS);

            echo ',"feedback":'.json_encode(Feedback::query()->with(['project', 'item.project'])->orderBy('id')->get()->map(fn (Feedback $entry) => [
                'title' => $entry->title,
                'body' => $entry->body,
                'source' => $entry->source,
                'status' => $entry->status,
                'project' => $entry->project?->key,
                'item' => $entry->item?->key,
                'submitter_name' => $entry->submitter_name,
                'submitter_email' => $entry->submitter_email,
                'created_at' => $entry->created_at,
            ]), self::FLAGS);

            echo '}';
        }, "yadeit-{$workspace->slug}-".now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
    }

    /** @return list<array<string, mixed>> */
    private function members(Workspace $workspace): array
    {
        return DB::table('workspace_memberships')
            ->join('users', 'users.id', '=', 'workspace_memberships.user_id')
            ->where('workspace_memberships.workspace_id', $workspace->id)->orderBy('users.id')
            ->get(['users.name', 'users.email', 'users.type', 'workspace_memberships.role', 'workspace_memberships.created_at as joined_at'])
            // An agent's address is internal and means nothing outside.
            ->map(fn (object $row) => ['name' => $row->name, 'email' => $row->type === 'human' ? $row->email : null, 'type' => $row->type, 'role' => $row->role, 'joined_at' => $row->joined_at])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function projects(): array
    {
        return Project::query()->with(['states', 'labels', 'cycles'])->orderBy('id')->get()->map(fn (Project $project) => [
            'key' => $project->key,
            'name' => $project->name,
            'description' => $project->description,
            'archived' => $project->archived_at !== null,
            'cycle_weeks' => $project->cycle_weeks,
            'stale_after_days' => $project->stale_after_days,
            'states' => $project->states->sortBy('position')->map(fn ($s) => ['name' => $s->name, 'group' => $s->group, 'color' => $s->color, 'is_default' => (bool) $s->is_default])->values(),
            'labels' => $project->labels->map(fn ($l) => ['name' => $l->name, 'color' => $l->color])->values(),
            'cycles' => $project->cycles->map(fn ($c) => ['number' => $c->number, 'starts_on' => $c->starts_on, 'ends_on' => $c->ends_on, 'closed_at' => $c->closed_at])->values(),
            'created_at' => $project->created_at,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function item(WorkItem $item): array
    {
        return [
            'key' => $item->key,
            'title' => $item->title,
            'description' => $item->description,
            'state' => $item->state?->name,
            'priority' => $item->priority,
            'kind' => $item->kind,
            'assignee' => $item->assignee?->name,
            'delegate' => $item->delegate?->name,
            'created_by' => $item->creator?->name,
            'parent' => $item->parent?->key,
            'labels' => $item->labels->pluck('name'),
            'cycle' => $item->cycle?->number,
            'due_date' => $item->due_date,
            'created_at' => $item->created_at,
            'completed_at' => $item->completed_at,
            'checklist' => $item->checklist->map(fn ($c) => ['text' => $c->text, 'done' => (bool) $c->is_done]),
            'links' => $item->links->map(fn ($l) => ['kind' => $l->kind, 'url' => $l->url, 'title' => $l->title]),
            'relations' => $item->outgoingRelations->map(fn ($r) => ['type' => $r->type, 'item' => $r->related?->key]),
            'comments' => $item->comments->map(fn ($c) => ['author' => $c->author?->name, 'body' => $c->body, 'created_at' => $c->created_at]),
            'attachments' => $item->attachments->map(fn ($a) => ['name' => $a->name, 'mime_type' => $a->mime_type, 'size' => $a->size]),
        ];
    }
}
