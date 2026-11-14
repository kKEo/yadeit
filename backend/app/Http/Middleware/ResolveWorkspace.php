<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the {workspace} route parameter, checks that the signed-in user is
 * a member, and makes it the current workspace. Runs before route model
 * binding so that tenant models bound later are already scoped.
 *
 * Non-members get 404, not 403: a workspace's existence is not disclosed.
 */
class ResolveWorkspace
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $parameter = $request->route('workspace');
        $user = $request->user();

        $workspace = $parameter instanceof Workspace
            ? $parameter
            : Workspace::query()->where('slug', (string) $parameter)->first();

        $membership = ($workspace && $user) ? $user->membershipIn($workspace) : null;

        if (! $workspace || ! $membership) {
            throw new NotFoundHttpException('Workspace not found.');
        }

        if ($workspace->suspended_at !== null) {
            throw new ApiException(403, 'workspace_suspended', 'This workspace is suspended. Contact whoever runs this installation.');
        }

        $this->current->set($workspace, $membership);
        $request->route()->setParameter('workspace', $workspace);

        return $next($request);
    }
}
