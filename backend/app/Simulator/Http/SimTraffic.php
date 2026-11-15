<?php

namespace App\Simulator\Http;

use App\Simulator\Models\SimRun;
use App\Simulator\SimContext;
use App\Simulator\Simulator;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recognises requests of simulated people. A valid signature of a run that is
 * going on marks the request, which makes address-based limits count per
 * virtual client, and adds a Server-Timing header the runner reads. Anything
 * else passes untouched: a wrong or stale signature gains nothing.
 */
class SimTraffic
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get(Simulator::HEADER);

        if (! is_string($header) || ! Simulator::enabled() || ($context = $this->verify($header)) === null) {
            return $next($request);
        }

        $request->attributes->set('sim', $context);

        $queries = 0;
        $dbMs = 0.0;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$dbMs): void {
            $queries++;
            $dbMs += $query->time;
        });
        $started = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        $response = $next($request);

        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%.1f, db;dur=%.1f, queries;desc="%d"',
            (microtime(true) - $started) * 1000, $dbMs, $queries,
        ));

        return $response;
    }

    private function verify(string $header): ?SimContext
    {
        if (preg_match('/^(\d{1,18})\.([A-Za-z0-9_-]{1,40})\.([a-f0-9]{64})$/', $header, $m) !== 1) {
            return null;
        }

        $run = SimRun::query()->find((int) $m[1]);

        if ($run === null || ! $run->status->isActive() || ! hash_equals($run->signature($m[2]), $m[3])) {
            return null;
        }

        return new SimContext($run->id, $m[2]);
    }
}
