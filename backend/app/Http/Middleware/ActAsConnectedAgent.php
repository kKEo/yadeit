<?php

namespace App\Http\Middleware;

use App\Models\McpConnection;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * An app connected over OAuth signs in as a person, but works as the agent
 * that person chose for it. From here on the request is the agent's: the same
 * guardrails and the same history as an agent with an access token.
 */
class ActAsConnectedAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isAgent()) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        $agent = null;

        if ($token instanceof AccessToken && $token->can(Registrar::OAUTH_SCOPE)) {
            $agent = McpConnection::query()->where('user_id', $user->id)->where('client_id', $token->oauth_client_id)->first()?->agent;
        }

        // The agent must still be this person's own.
        if (! $agent instanceof User || ! $agent->isAgent() || $agent->owner_id !== $user->id) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $request->json('id'),
                'error' => ['code' => -32001, 'message' => 'This connection is no longer linked to an agent. Connect the app again and choose one of your agents.'],
            ], 403);
        }

        // Requests the tools make inside the application are signed in through this guard.
        Auth::guard('web')->setUser($agent);
        Auth::shouldUse('web');
        $request->setUserResolver(fn () => $agent);
        $request->attributes->set('mcp.oauth', true);

        return $next($request);
    }
}
