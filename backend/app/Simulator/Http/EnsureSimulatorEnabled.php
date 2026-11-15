<?php

namespace App\Simulator\Http;

use App\Simulator\Simulator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** With the Traffic lab switched off, its addresses do not exist. */
class EnsureSimulatorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Simulator::enabled()) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
