<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceInvitation;
use App\Support\CurrentWorkspace;
use App\Support\Plans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InvitationController extends Controller
{
    public function index(Workspace $workspace, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Admin);

        $invitations = $workspace->invitations()
            ->whereNull('accepted_at')
            ->latest()
            ->get()
            ->map(fn (Invitation $i) => $this->present($i));

        return response()->json(['data' => $invitations]);
    }

    public function store(Request $request, Workspace $workspace, CurrentWorkspace $current): JsonResponse
    {
        $current->requireRole(WorkspaceRole::Admin);

        if (! $request->user()->hasVerifiedEmail()) {
            throw new AccessDeniedHttpException('Confirm your email address before inviting people.');
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['sometimes', Rule::enum(WorkspaceRole::class)],
        ]);

        $role = WorkspaceRole::from($data['role'] ?? WorkspaceRole::Member->value);

        Plans::ensureRoom($workspace, 'members');

        if ($role === WorkspaceRole::Owner && $current->role() !== WorkspaceRole::Owner) {
            throw new AccessDeniedHttpException('Only an owner can invite another owner.');
        }

        $alreadyMember = $workspace->memberships()
            ->whereHas('user', fn ($query) => $query->where('email', $data['email']))
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => ['This person is already a member.']]);
        }

        $token = Str::random(48);

        // Inviting the same address again replaces the earlier, still open invitation.
        $invitation = DB::transaction(function () use ($workspace, $data, $role, $token, $request): Invitation {
            $workspace->invitations()->where('email', $data['email'])->whereNull('accepted_at')->delete();

            return $workspace->invitations()->create([
                'email' => $data['email'],
                'role' => $role,
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
            ]);
        });

        Notification::route('mail', $invitation->email)
            ->notify(new WorkspaceInvitation($invitation, $token));

        return response()->json(['data' => $this->present($invitation)], 201);
    }

    public function destroy(Workspace $workspace, int $invitation, CurrentWorkspace $current): Response
    {
        $current->requireRole(WorkspaceRole::Admin);

        $deleted = $workspace->invitations()->whereKey($invitation)->whereNull('accepted_at')->delete();

        if ($deleted === 0) {
            throw new NotFoundHttpException('Invitation not found.');
        }

        return response()->noContent();
    }

    /** Public: lets the invited person see what they are joining before signing in. */
    public function preview(string $token): JsonResponse
    {
        $invitation = $this->usable($token);

        return response()->json(['data' => [
            'workspace' => ['name' => $invitation->workspace->name],
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at,
            'has_account' => User::query()->where('email', $invitation->email)->exists(),
        ]]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $user = $request->user();

        $workspace = DB::transaction(function () use ($token, $user): Workspace {
            $invitation = Invitation::query()->whereKey($this->usable($token)->id)->lockForUpdate()->first();

            if (! $invitation || $invitation->accepted_at !== null) {
                throw new NotFoundHttpException('This invitation is no longer valid.');
            }

            if (strtolower($user->email) !== strtolower($invitation->email)) {
                throw new AccessDeniedHttpException('This invitation was sent to a different email address.');
            }

            $invitation->workspace->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            // Opening the emailed link proves the address belongs to this user.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            return $invitation->workspace;
        });

        return response()->json(['data' => [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug],
        ]]);
    }

    private function usable(string $token): Invitation
    {
        return Invitation::findUsableByToken($token)
            ?? throw new NotFoundHttpException('This invitation is no longer valid.');
    }

    /** @return array<string, mixed> */
    private function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at,
            'created_at' => $invitation->created_at,
        ];
    }
}
