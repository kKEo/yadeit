<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint is for agents: the caller must be an agent that still
 * belongs to its workspace and is using an access token. The workspace is
 * remembered on the request for the tools.
 */
class EnterAgentWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $workspace = $user instanceof User && $user->isAgent() && $request->bearerToken() !== null
            ? Workspace::query()
                ->whereKey($user->workspace_id)
                ->whereHas('memberships', fn ($q) => $q->where('user_id', $user->id))
                ->first()
            : null;

        if (! $workspace) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $request->json('id'),
                'error' => [
                    'code' => -32001,
                    'message' => 'This endpoint is for agents. Create an agent in the web app (Agents), create a token for it, and send it as a bearer token.',
                ],
            ], 403);
        }

        $request->attributes->set('mcp.workspace', $workspace);

        return $next($request);
    }
}
