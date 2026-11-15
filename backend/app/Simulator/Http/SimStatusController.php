<?php

namespace App\Simulator\Http;

use App\Http\Controllers\Controller;
use App\Simulator\Models\SimRun;
use App\Simulator\SimReport;
use App\Simulator\SimRuns;
use App\Simulator\SimRunStatus;
use Illuminate\Http\JsonResponse;

class SimStatusController extends Controller
{
    /** Whether the Traffic lab may be used here, and within which limits. */
    public function __invoke(): JsonResponse
    {
        $active = array_map(fn (SimRunStatus $s) => $s->value, array_filter(SimRunStatus::cases(), fn (SimRunStatus $s) => $s->isActive()));

        return response()->json(['data' => [
            'enabled' => true,
            'production' => app()->environment('production'),
            'caps' => config('simulator.caps'),
            'speeds' => config('simulator.speeds'),
            'volumes' => array_keys((array) config('simulator.volumes')),
            'mail_domain' => config('simulator.mail_domain'),
            'defaults' => SimRuns::DEFAULTS,
            'thresholds' => SimReport::THRESHOLDS,
            // With a standing runner a run starts playing by itself; without one, each run shows a token to start a runner with.
            // Whether the AI layers can be used at all: they need the installation's provider key.
            'ai_available' => filled(config('ai.api_key')),
            'standing_runner' => filled(config('simulator.runner_token')),
            'active_run_id' => SimRun::query()->whereIn('status', $active)->orderByDesc('id')->value('id'),
        ]]);
    }
}
