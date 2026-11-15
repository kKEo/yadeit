<?php

namespace App\Simulator;

use App\Models\Workspace;
use App\Simulator\Models\SimRun;
use Illuminate\Http\Request;

/** The switches and small rules of the Traffic lab, in one place. */
class Simulator
{
    /** The header a runner sends with every request of a simulated person: "<run>.<client>.<signature>". */
    public const HEADER = 'X-Sim-Run';

    public static function enabled(): bool
    {
        if (! config('simulator.enabled')) {
            return false;
        }

        return ! app()->environment('production') || (bool) config('simulator.allow_production');
    }

    public static function mailDomain(): string
    {
        return mb_strtolower((string) config('simulator.mail_domain'));
    }

    public static function isSimEmail(string $email): bool
    {
        return str_ends_with(mb_strtolower($email), '@'.self::mailDomain());
    }

    public static function email(int $runId, int $teamNumber, int $memberNumber): string
    {
        return "r{$runId}-t{$teamNumber}-m{$memberNumber}@".self::mailDomain();
    }

    /** The run an address belongs to, read from the address itself. */
    public static function runIdOf(string $email): ?int
    {
        return self::isSimEmail($email) && preg_match('/^r(\d+)-/', $email, $m) === 1 ? (int) $m[1] : null;
    }

    /** Set by the SimTraffic middleware when the request carries a valid signature of a run that is going on. */
    public static function context(Request $request): ?SimContext
    {
        $context = $request->attributes->get('sim');

        return $context instanceof SimContext ? $context : null;
    }

    /**
     * What limits that count per address count by instead. All simulated people
     * share the runner's address; each one is counted on their own.
     */
    public static function callerKey(Request $request): string
    {
        $context = self::context($request);

        return $context !== null ? "sim:{$context->runId}:{$context->client}" : (string) $request->ip();
    }

    /**
     * How much more a simulated caller may do per minute than a real one. A
     * run at 50x packs fifty minutes of one person's work into one minute, and
     * limits count by the server's clock; without this every fast run would
     * end in "too many requests" that no real person would ever see. At 1x
     * the limits are exactly the real ones.
     */
    public static function allowance(Request $request, int $perMinute): int
    {
        return $perMinute * (self::context($request)->speed ?? 1);
    }

    /** The only address a simulated workspace may send webhooks to. */
    public static function sinkUrl(): string
    {
        return (string) (config('simulator.sink_url') ?: rtrim((string) config('app.url'), '/').'/api/v1/sim/sink');
    }

    public static function isSink(string $url): bool
    {
        return self::enabled() && $url === self::sinkUrl();
    }

    /** Simulated workspaces get canned AI answers unless their run asks for the real model. */
    public static function usesCannedAi(Workspace $workspace): bool
    {
        if ($workspace->sim_run_id === null) {
            return false;
        }

        return ! (bool) data_get(SimRun::query()->find($workspace->sim_run_id)?->config, 'ai.real_help', false);
    }
}
