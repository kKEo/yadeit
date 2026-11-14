<?php

use App\Models\User;
use App\Support\TokenScope;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

// Realtime signals carry identifiers only; these rules decide who may listen.

Broadcast::channel('workspace.{workspaceId}', function (User $user, int $workspaceId) {
    // The channel speaks about every project, so a token limited to some of them may not listen.
    if (request()->bearerToken() !== null && TokenScope::projectIds($user->currentAccessToken()) !== null) {
        return false;
    }

    return DB::table('workspace_memberships')
        ->join('workspaces', 'workspaces.id', '=', 'workspace_memberships.workspace_id')
        ->where('workspace_memberships.workspace_id', $workspaceId)
        ->where('workspace_memberships.user_id', $user->id)
        ->whereNull('workspaces.suspended_at')
        ->exists();
});

Broadcast::channel('user.{userId}', fn (User $user, int $userId) => $user->id === $userId);
