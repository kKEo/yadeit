<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account can do nothing, whatever session or token it still
 * holds. An agent acts for its owner, so it stops when the owner is
 * suspended, gone, or no longer a full member of the agent's workspace.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            self::check($user);
        }

        return $next($request);
    }

    public static function check(User $user): void
    {
        if ($user->suspended_at !== null || ($user->isAgent() && $user->owner?->suspended_at !== null)) {
            throw new ApiException(403, 'account_suspended', 'This account is suspended. Contact whoever runs this installation.');
        }

        if ($user->isAgent() && ! self::ownerIsMember($user)) {
            throw new ApiException(403, 'agent_without_owner',
                'The person this agent works for is no longer a member of the workspace, so the agent has stopped. An admin can remove it and a member can create a new one.');
        }
    }

    private static function ownerIsMember(User $agent): bool
    {
        return $agent->owner_id !== null && DB::table('workspace_memberships')
            ->where('workspace_id', $agent->workspace_id)
            ->where('user_id', $agent->owner_id)
            ->whereIn('role', ['owner', 'admin', 'member'])
            ->exists();
    }
}
