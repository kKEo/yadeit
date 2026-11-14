<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\TokenScope;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies what a bearer token is allowed to do: read-only tokens cannot
 * change anything, project-limited tokens see only their projects, and
 * agents stay inside their own workspace. Cookie sessions pass untouched.
 *
 * Runs before route model binding, so a project or item outside the token's
 * projects is "not found" rather than loaded and then refused.
 */
class EnforceTokenScope
{
    /** Routes outside a workspace that an agent may still call. */
    private const AGENT_GLOBAL_ROUTES = ['me.show', 'workspaces.index'];

    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;
        $routeName = (string) $request->route()?->getName();

        if ($user instanceof User && $user->isAgent()) {
            if (! $token instanceof PersonalAccessToken) {
                throw new ApiException(403, 'agent_needs_token', 'Agents act through an access token only.');
            }

            $inWorkspace = $request->route()?->hasParameter('workspace') ?? false;

            if (! $inWorkspace && ! in_array($routeName, self::AGENT_GLOBAL_ROUTES, true)) {
                throw new ApiException(403, 'not_available_to_agents', 'Agents can only work inside their workspace: /api/v1/workspaces/{workspace}/...');
            }
        }

        if ($token instanceof PersonalAccessToken) {
            if (! $request->isMethodSafe() && $token->cant(TokenScope::WRITE)) {
                throw new ApiException(403, 'token_read_only', 'This token is read-only. Ask the agent\'s owner for a token with write access.');
            }

            $projectIds = TokenScope::projectIds($token);

            if ($projectIds !== null) {
                if ($routeName === 'projects.store') {
                    throw new ApiException(403, 'token_project_scope', 'This token is limited to specific projects and cannot create new ones.');
                }

                $this->current->limitToProjects($projectIds);
            }
        }

        return $next($request);
    }
}
