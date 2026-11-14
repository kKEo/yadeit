<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

// Realtime signals carry identifiers only; these rules decide who may listen.

Broadcast::channel('workspace.{workspaceId}', fn (User $user, int $workspaceId) => DB::table('workspace_memberships')
    ->where('workspace_id', $workspaceId)
    ->where('user_id', $user->id)
    ->exists());

Broadcast::channel('user.{userId}', fn (User $user, int $userId) => $user->id === $userId);
