<?php

namespace App\Simulator\Http;

use App\Http\Controllers\Controller;
use App\Simulator\Models\SimRun;
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
            'active_run_id' => SimRun::query()->whereIn('status', $active)->orderByDesc('id')->value('id'),
        ]]);
    }
}
