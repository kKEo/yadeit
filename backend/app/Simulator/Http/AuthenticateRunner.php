<?php

namespace App\Simulator\Http;

use App\Exceptions\ApiException;
use App\Simulator\Models\SimRun;
use App\Simulator\SimRuns;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/** A runner signs in with the token of its run ("<run>|<secret>"), or as a standing runner with the installation's token ("standby|<secret>"). */
class AuthenticateRunner
{
    public function handle(Request $request, Closure $next): Response
    {
        [$id, $secret] = array_pad(explode('|', (string) $request->bearerToken(), 2), 2, '');

        // A runner that is always there signs in with the installation's token and plays whichever run is going on.
        if ($id === 'standby') {
            $expected = (string) config('simulator.runner_token');

            if ($expected === '' || ! hash_equals($expected, $secret)) {
                throw new UnauthorizedHttpException('Bearer', 'Unknown runner token.');
            }

            $request->attributes->set('sim_run', app(SimRuns::class)->active() ?? throw new ApiException(409, 'no_run', 'No run is going on.'));

            return $next($request);
        }

        $run = ctype_digit($id) ? SimRun::query()->find((int) $id) : null;

        if ($run === null || $run->runner_token_hash === null || ! hash_equals($run->runner_token_hash, hash('sha256', $secret))) {
            throw new UnauthorizedHttpException('Bearer', 'Unknown runner token.');
        }

        $request->attributes->set('sim_run', $run);

        return $next($request);
    }
}
