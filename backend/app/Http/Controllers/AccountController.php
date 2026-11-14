<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing;
use App\Support\Plans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** A person's own data: taking a copy of it, and deleting the account. */
class AccountController extends Controller
{
    /** Everything this account wrote or belongs to, as one JSON file. */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $key = fn (object $row): string => $row->project_key.'-'.$row->sequence;

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'email_notifications' => (bool) $user->email_notifications,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'workspaces' => DB::table('workspace_memberships')
                ->join('workspaces', 'workspaces.id', '=', 'workspace_memberships.workspace_id')
                ->where('workspace_memberships.user_id', $user->id)->orderBy('workspaces.name')
                ->get(['workspaces.name', 'workspaces.slug', 'workspace_memberships.role', 'workspace_memberships.created_at as joined_at']),
            'agents' => DB::table('users')->join('workspaces', 'workspaces.id', '=', 'users.workspace_id')
                ->where('users.owner_id', $user->id)->where('users.type', 'agent')->orderBy('users.id')
                ->get(['users.name', 'users.description', 'workspaces.slug as workspace', 'users.created_at']),
            'items_created' => DB::table('work_items')
                ->join('projects', 'projects.id', '=', 'work_items.project_id')
                ->join('workspaces', 'workspaces.id', '=', 'work_items.workspace_id')
                ->where('work_items.created_by', $user->id)->whereNull('work_items.deleted_at')->orderBy('work_items.id')
                ->get(['projects.key as project_key', 'work_items.sequence', 'work_items.title', 'work_items.description', 'workspaces.slug as workspace', 'work_items.created_at'])
                ->map(fn (object $row) => ['key' => $key($row), 'workspace' => $row->workspace, 'title' => $row->title, 'description' => $row->description, 'created_at' => $row->created_at]),
            'comments' => DB::table('comments')
                ->join('work_items', 'work_items.id', '=', 'comments.work_item_id')
                ->join('projects', 'projects.id', '=', 'work_items.project_id')
                ->join('workspaces', 'workspaces.id', '=', 'comments.workspace_id')
                ->where('comments.user_id', $user->id)->orderBy('comments.id')
                ->get(['projects.key as project_key', 'work_items.sequence', 'comments.body', 'workspaces.slug as workspace', 'comments.created_at'])
                ->map(fn (object $row) => ['item' => $key($row), 'workspace' => $row->workspace, 'body' => $row->body, 'created_at' => $row->created_at]),
            'pages' => DB::table('pages')->join('workspaces', 'workspaces.id', '=', 'pages.workspace_id')
                ->where('pages.created_by', $user->id)->whereNull('pages.deleted_at')->orderBy('pages.id')
                ->get(['pages.title', 'pages.body', 'workspaces.slug as workspace', 'pages.created_at']),
            'feedback' => DB::table('feedback')->join('workspaces', 'workspaces.id', '=', 'feedback.workspace_id')
                ->where('feedback.created_by', $user->id)->orderBy('feedback.id')
                ->get(['feedback.title', 'feedback.body', 'workspaces.slug as workspace', 'feedback.created_at']),
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="yadeit-my-data-'.now()->format('Y-m-d').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Deletes the account. Needs the password. What the person wrote in shared
     * workspaces stays there without their name; a workspace they were alone in
     * goes with them; their agents are removed.
     */
    public function destroy(Request $request): Response
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['password' => ['This is not your password.']]);
        }

        [$alone, $blocked] = $this->ownedWorkspaces($user);

        if ($blocked !== []) {
            throw new ApiException(409, 'sole_owner',
                'You are the only owner of: '.implode(', ', array_column($blocked, 'name')).'. Make someone else an owner there, or delete the workspace, and then delete your account.',
                ['workspaces' => array_column($blocked, 'slug')]);
        }

        foreach ($alone as $workspace) {
            if (Plans::billingEnabled() && $workspace->subscribed(Billing::SUBSCRIPTION) && ! $workspace->planSubscription()?->canceled()) {
                throw new ApiException(409, 'active_subscription',
                    "The workspace {$workspace->name} has a paid plan. Cancel it under Settings → Manage billing first.", ['workspaces' => [$workspace->slug]]);
            }
        }

        if ($user->is_operator && User::query()->where('is_operator', true)->where('id', '!=', $user->id)->doesntExist()) {
            throw new ApiException(409, 'last_operator', 'You are the only operator of this installation. Make someone else an operator first.');
        }

        // Signing out comes first: it still writes to the account's row, and after the deletion that would bring the row back.
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        DB::transaction(function () use ($user, $alone): void {
            foreach ($alone as $workspace) {
                $workspace->delete();
            }

            // An agent acts for its owner; without one it must not keep working.
            User::query()->where('owner_id', $user->id)->where('type', 'agent')->get()->each(function (User $agent): void {
                $agent->tokens()->delete();
                $agent->delete();
            });

            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return response()->noContent();
    }

    /**
     * The workspaces this person is the last owner of: the ones nobody else is
     * in (deleted with the account) and the ones other people still use (which
     * block the deletion).
     *
     * @return array{list<Workspace>, list<array{name: string, slug: string}>}
     */
    private function ownedWorkspaces(User $user): array
    {
        $alone = $blocked = [];

        $owned = Workspace::query()->whereIn('id', DB::table('workspace_memberships')
            ->where('user_id', $user->id)->where('role', 'owner')->select('workspace_id'))->get();

        foreach ($owned as $workspace) {
            $others = DB::table('workspace_memberships')
                ->join('users', 'users.id', '=', 'workspace_memberships.user_id')
                ->where('workspace_memberships.workspace_id', $workspace->id)
                ->where('users.type', 'human')->where('users.id', '!=', $user->id)
                ->pluck('workspace_memberships.role');

            if ($others->isEmpty()) {
                $alone[] = $workspace;
            } elseif (! $others->contains('owner')) {
                $blocked[] = ['name' => $workspace->name, 'slug' => $workspace->slug];
            }
        }

        return [$alone, $blocked];
    }
}
