<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs in the caller of the MCP endpoint: an agent with its access token,
 * or an app connected over OAuth. OAuth needs signing keys; on an
 * installation without them only access tokens are accepted, and the
 * endpoint stays up.
 */
class AuthenticateMcp
{
    public function __construct(private Authenticate $authenticate) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->authenticate->handle($request, $next, ...(self::oauthReady() ? ['sanctum', 'api'] : ['sanctum']));
    }

    /** Whether the keys that sign OAuth tokens exist (`php artisan passport:keys`, or the PASSPORT_*_KEY settings). */
    public static function oauthReady(): bool
    {
        return (filled(config('passport.private_key')) && filled(config('passport.public_key')))
            || (is_file(Passport::keyPath('oauth-private.key')) && is_file(Passport::keyPath('oauth-public.key')));
    }
}
