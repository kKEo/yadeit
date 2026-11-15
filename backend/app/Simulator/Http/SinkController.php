<?php

namespace App\Simulator\Http;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Simulator\Models\SimFinding;
use App\Simulator\SimMetrics;
use App\Simulator\SimRuns;
use App\Simulator\Simulator;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where the webhooks of simulated workspaces arrive. It does what a careful
 * receiver does: checks the signature against the secret of one of the run's
 * webhooks. A delivery that verifies is counted, with how long it took to
 * arrive; one that does not becomes a finding.
 */
class SinkController extends Controller
{
    public function __invoke(Request $request, SimRuns $runs, SimMetrics $metrics, CurrentWorkspace $current): JsonResponse
    {
        $run = $runs->active();
        $body = $request->getContent();
        preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', (string) $request->header('X-Yadeit-Signature'), $m);

        if ($run === null) {
            return response()->json(['data' => ['received' => false]], 202);
        }

        $workspaceIds = DB::table('workspaces')->where('sim_run_id', $run->id)->pluck('id');
        $verified = $m !== [] && $current->runUnscoped(fn () => Webhook::query()
            ->whereIn('workspace_id', $workspaceIds)->where('url', Simulator::sinkUrl())->get()
            ->contains(fn (Webhook $webhook) => hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $webhook->secret), $m[2])));

        if (! $verified) {
            SimFinding::create([
                'run_id' => $run->id, 'flow' => 'webhookDelivery', 'step' => 'signature', 'severity' => 'error', 'created_at' => now(),
                'message' => 'A webhook arrived whose signature matches none of the run\'s webhooks.',
                'details' => ['event' => $request->header('X-Yadeit-Event')],
            ]);
        }

        // The signature carries the second it was made; the difference is how long the delivery took, queue included.
        $ms = $m === [] ? 0 : max(0, (int) round((microtime(true) - (int) $m[1]) * 1000));
        $metrics->ingest($run, [[
            'at' => now()->getTimestamp(), 'kind' => 'flow', 'name' => 'webhookDelivery', 'count' => 1, 'errors' => $verified ? 0 : 1,
            'sum_ms' => $ms, 'max_ms' => $ms, 'histogram' => SimMetrics::histogramOf($ms),
        ]], []);

        return response()->json(['data' => ['received' => $verified]], $verified ? 200 : 400);
    }
}
