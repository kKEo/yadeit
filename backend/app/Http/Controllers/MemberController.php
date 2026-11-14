<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MemberController extends Controller
{
    public function index(Workspace $workspace): JsonResponse
    {
        $members = $workspace->memberships()->with('user')->get()
            ->sortBy(fn (Membership $m) => [-$m->role->rank(), $m->user->name])
            ->map(fn (Membership $m) => $this->present($m))
            ->values();

        return response()->json(['data' => $members]);
    }

    public function update(Request $request, Workspace $workspace, int $user, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Admin);

        $data = $request->validate(['role' => ['required', Rule::enum(WorkspaceRole::class)]]);
        $newRole = WorkspaceRole::from($data['role']);

        $membership = DB::transaction(function () use ($workspace, $user, $current, $newRole): Membership {
            $membership = $this->find($workspace, $user);
            $this->guardOwnerChange($current, $membership, $newRole);

            if ($membership->role === WorkspaceRole::Owner && $newRole !== WorkspaceRole::Owner) {
                $this->guardLastOwner($workspace);
            }

            $membership->update(['role' => $newRole]);

            return $membership;
        });

        return response()->json(['data' => $this->present($membership->load('user'))]);
    }

    /** Removes a member, or lets a member leave when {user} is their own id. */
    public function destroy(Request $request, Workspace $workspace, int $user, CurrentWorkspace $current): Response
    {
        $isLeaving = $request->user()->id === $user;

        if (! $isLeaving) {
            $current->requireRole(WorkspaceRole::Admin);
        }

        DB::transaction(function () use ($workspace, $user, $current, $isLeaving): void {
            $membership = $this->find($workspace, $user);

            if (! $isLeaving) {
                $this->guardOwnerChange($current, $membership, null);
            }

            if ($membership->role === WorkspaceRole::Owner) {
                $this->guardLastOwner($workspace);
            }

            $membership->delete();
        });

        return response()->noContent();
    }

    private function find(Workspace $workspace, int $userId): Membership
    {
        return $workspace->memberships()->where('user_id', $userId)->lockForUpdate()->first()
            ?? throw new NotFoundHttpException('Member not found.');
    }

    /** Only owners may change an owner or hand out the owner role. */
    private function guardOwnerChange(CurrentWorkspace $current, Membership $target, ?WorkspaceRole $newRole): void
    {
        $touchesOwner = $target->role === WorkspaceRole::Owner || $newRole === WorkspaceRole::Owner;

        if ($touchesOwner && $current->role() !== WorkspaceRole::Owner) {
            throw new AccessDeniedHttpException('Only an owner can change owners.');
        }
    }

    private function guardLastOwner(Workspace $workspace): void
    {
        // Lock the owner rows themselves: PostgreSQL refuses FOR UPDATE on an aggregate.
        $ownerIds = $workspace->memberships()->where('role', WorkspaceRole::Owner)->lockForUpdate()->pluck('id')->all();

        if (count($ownerIds) <= 1) {
            throw new ConflictHttpException('A workspace needs at least one owner. Make someone else an owner first.');
        }
    }

    /** @return array<string, mixed> */
    private function present(Membership $membership): array
    {
        return [
            'id' => $membership->user_id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role->value,
            'joined_at' => $membership->created_at,
        ];
    }
}
