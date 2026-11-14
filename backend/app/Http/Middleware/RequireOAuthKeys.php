<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** For the addresses that only make sense with OAuth: on an installation without signing keys they do not exist. */
class RequireOAuthKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AuthenticateMcp::oauthReady(), 404);

        return $next($request);
    }
}
