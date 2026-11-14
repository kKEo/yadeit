<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A suspended account can do nothing, whatever session or token it still holds. An agent stops with its owner. */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->suspended_at !== null || ($user?->isAgent() && $user->owner?->suspended_at !== null)) {
            throw new ApiException(403, 'account_suspended', 'This account is suspended. Contact whoever runs this installation.');
        }

        return $next($request);
    }
}
