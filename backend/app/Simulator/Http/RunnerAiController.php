<?php

namespace App\Simulator\Http;

use App\Http\Controllers\Controller;
use App\Simulator\Models\SimMember;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimTeam;
use App\Simulator\SimAi;
use App\Simulator\SimRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Where runners ask for what only a model can give: a team's story for a day, an explorer's next step. */
class RunnerAiController extends Controller
{
    public function __construct(private SimAi $ai, private SimRuns $runs) {}

    /** The steps of one team's day. Empty when the run has no director and replays nothing. */
    public function storyline(Request $request): JsonResponse
    {
        $run = $this->run($request);
        $data = $request->validate(['team_id' => ['required', 'integer'], 'sim_day' => ['required', 'integer', 'min:0', 'max:100000']]);
        $team = SimTeam::query()->where('run_id', $run->id)->findOrFail($data['team_id']);

        $steps = $this->ai->storyline($run, $team, $data['sim_day']);
        $this->runs->enforceLimits($run->refresh());

        return response()->json(['data' => ['steps' => $steps]]);
    }

    public function decide(Request $request): JsonResponse
    {
        $run = $this->run($request);
        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'options' => ['required', 'array', 'min:1', 'max:60'], 'options.*' => ['string', 'max:40'],
            'history' => ['sometimes', 'array', 'max:20'], 'history.*' => ['string', 'max:300'],
            'seen' => ['sometimes', 'string', 'max:4000'],
        ]);
        $member = SimMember::query()->where('run_id', $run->id)->findOrFail($data['member_id']);

        $choice = $this->ai->decide($run, $member, $data['options'], $data['history'] ?? [], $data['seen'] ?? '');
        $this->runs->enforceLimits($run->refresh());

        return response()->json(['data' => $choice]);
    }

    private function run(Request $request): SimRun
    {
        /** @var SimRun */
        return $request->attributes->get('sim_run');
    }
}
