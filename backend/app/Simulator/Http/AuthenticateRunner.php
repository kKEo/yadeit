<?php

namespace App\Simulator\Http;

use App\Simulator\Models\SimRun;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/** A runner signs in with the token of its run ("<run>|<secret>"). The token works for as long as the run exists. */
class AuthenticateRunner
{
    public function handle(Request $request, Closure $next): Response
    {
        [$id, $secret] = array_pad(explode('|', (string) $request->bearerToken(), 2), 2, '');
        $run = ctype_digit($id) ? SimRun::query()->find((int) $id) : null;

        if ($run === null || $run->runner_token_hash === null || ! hash_equals($run->runner_token_hash, hash('sha256', $secret))) {
            throw new UnauthorizedHttpException('Bearer', 'Unknown runner token.');
        }

        $request->attributes->set('sim_run', $run);

        return $next($request);
    }
}
