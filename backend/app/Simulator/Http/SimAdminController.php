<?php

namespace App\Simulator\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Simulator\Actions\PurgeSimRun;
use App\Simulator\ContentPack;
use App\Simulator\Models\SimContentPack;
use App\Simulator\Models\SimFinding;
use App\Simulator\Models\SimMember;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimRunner;
use App\Simulator\Models\SimScenario;
use App\Simulator\Models\SimTeam;
use App\Simulator\SimReport;
use App\Simulator\SimRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The Traffic lab as the operator sees it: scenarios, starting and steering a run, its report, cleaning up. */
class SimAdminController extends Controller
{
    public function __construct(private SimRuns $runs, private SimReport $reports) {}

    public function scenarios(): JsonResponse
    {
        return response()->json(['data' => SimScenario::query()->orderBy('name')->get()->map(fn (SimScenario $s) => $this->presentScenario($s))]);
    }

    public function storeScenario(Request $request): JsonResponse
    {
        $data = $this->validatedScenario($request, creating: true);
        $scenario = SimScenario::create(['name' => $data['name'], 'config' => $this->runs->normalise($data['config'] ?? [])]);

        return response()->json(['data' => $this->presentScenario($scenario)], 201);
    }

    public function updateScenario(Request $request, int $id): JsonResponse
    {
        $scenario = SimScenario::query()->findOrFail($id);
        $data = $this->validatedScenario($request, creating: false);

        $scenario->update(array_filter([
            'name' => $data['name'] ?? null,
            'config' => isset($data['config']) ? $this->runs->normalise($data['config']) : null,
        ], fn ($value) => $value !== null));

        return response()->json(['data' => $this->presentScenario($scenario)]);
    }

    public function destroyScenario(int $id): JsonResponse
    {
        SimScenario::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** The built-in pack (id 0) and the generated ones, without their text. */
    public function packs(): JsonResponse
    {
        $builtIn = ContentPack::load();

        return response()->json(['data' => [
            ['id' => 0, 'name' => (string) ($builtIn->content['name'] ?? 'Default'), 'theme' => $builtIn->content['theme'] ?? null, 'source' => 'builtin', 'created_at' => null],
            ...SimContentPack::query()->orderByDesc('id')->get(['id', 'name', 'theme', 'source', 'created_at'])->toArray(),
        ]]);
    }

    public function pack(int $id): JsonResponse
    {
        return response()->json(['data' => ContentPack::load($id === 0 ? null : $id)->content]);
    }

    public function runs(): JsonResponse
    {
        return response()->json(['data' => SimRun::query()->orderByDesc('id')->limit(100)->get()->map(fn (SimRun $run) => $this->presentRun($run))]);
    }

    /** Starts a run from a saved scenario, from settings given here, or from both (the settings win). */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'scenario_id' => ['sometimes', 'nullable', 'integer'],
            'config' => ['sometimes', 'array'],
        ]);

        $scenario = isset($data['scenario_id']) ? SimScenario::query()->findOrFail($data['scenario_id']) : null;
        $config = array_replace((array) $scenario?->config, $this->validatedConfig((array) ($data['config'] ?? [])));

        [$run, $token] = $this->runs->start($config, $request->user(), $data['name'] ?? $scenario->name ?? 'Run');
        $run->update(['scenario_id' => $scenario?->id]);

        return response()->json(['data' => $this->presentRun($run) + [
            // Shown once. Not needed when a standing runner is configured.
            'runner_token' => $token,
        ]], 201);
    }

    /** Everything the live screen and the report page show. */
    public function run(int $id): JsonResponse
    {
        $run = SimRun::query()->findOrFail($id);

        if ($run->status->isActive()) {
            $this->runs->enforceLimits($run);
            $run->refresh();
        }

        $teams = SimTeam::query()->where('run_id', $run->id)->orderBy('id')->get();
        $members = SimMember::query()->where('run_id', $run->id)->orderBy('id')->get()->groupBy('team_id');
        $runners = SimRunner::query()->where('run_id', $run->id)->orderBy('id')->get();
        $activity = $runners->reduce(fn (array $all, SimRunner $r) => $all + (array) ($r->meta['activity'] ?? []), []);
        $slugs = DB::table('workspaces')->whereIn('id', $teams->pluck('workspace_id')->filter())->pluck('slug', 'id');

        // The measurements change every ten seconds; the screen asks every two.
        $report = $run->status->isActive()
            ? Cache::remember("sim-report-{$run->id}", 5, fn () => $this->reports->build($run))
            : $this->reports->build($run);

        return response()->json(['data' => [
            'run' => $this->presentRun($run),
            'teams' => $teams->map(fn (SimTeam $team) => [
                'id' => $team->id, 'name' => $team->name, 'size' => $team->size, 'onboarding' => $team->onboarding, 'status' => $team->status,
                'workspace' => $slugs[$team->workspace_id] ?? null,
                'members' => ($members[$team->id] ?? collect())->map(fn (SimMember $m) => [
                    'id' => $m->id, 'name' => $m->name, 'persona' => $m->persona, 'role' => $m->role, 'kind' => $m->kind,
                    'action' => $activity[$m->id] ?? null,
                ])->values(),
            ]),
            'runners' => $runners->map(fn (SimRunner $r) => [
                'id' => $r->id, 'name' => $r->name, 'host' => $r->host, 'lag_ms' => $r->lag_ms, 'last_seen_at' => $r->last_seen_at,
                'alive' => $r->last_seen_at !== null && $r->last_seen_at->gt(now()->subSeconds(20)),
            ]),
            'report' => $report,
            'findings' => SimFinding::query()->where('run_id', $run->id)->orderByDesc('id')->limit(50)->get(['id', 'flow', 'step', 'severity', 'message', 'details', 'team_id', 'member_id', 'created_at']),
        ]]);
    }

    /** Steering a run that is going on: its speed, pausing, and how many teams play. */
    public function control(Request $request, int $id): JsonResponse
    {
        $run = SimRun::query()->findOrFail($id);
        $data = $request->validate([
            'speed' => ['sometimes', 'integer', Rule::in((array) config('simulator.speeds'))],
            'paused' => ['sometimes', 'boolean'],
            'add_teams' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'remove_teams' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        if (isset($data['speed'])) {
            $this->runs->setSpeed($run, $data['speed']);
        }
        if (isset($data['paused'])) {
            $this->runs->pause($run, $data['paused']);
        }
        if (isset($data['add_teams'])) {
            $this->runs->addTeams($run, $data['add_teams']);
        }
        if (isset($data['remove_teams'])) {
            $this->runs->removeTeams($run, $data['remove_teams']);
        }

        return response()->json(['data' => $this->presentRun($run->refresh())]);
    }

    public function stop(int $id): JsonResponse
    {
        $run = $this->runs->stop(SimRun::query()->findOrFail($id), 'Stopped by an operator.');

        return response()->json(['data' => $this->presentRun($run->refresh())]);
    }

    /** Removes what the run created. With forget=true the run and its report go as well. */
    public function purge(Request $request, int $id, PurgeSimRun $purge): JsonResponse
    {
        $run = SimRun::query()->findOrFail($id);
        $data = $request->validate(['forget' => ['sometimes', Rule::in(['true', 'false', '1', '0'])]]);

        if ($run->status->isActive()) {
            throw new ApiException(409, 'conflict', 'Stop the run before removing it.');
        }

        $purge->handle($run, forget: filter_var($data['forget'] ?? false, FILTER_VALIDATE_BOOLEAN));

        return response()->json(null, 204);
    }

    public function compare(int $id, int $baseline): JsonResponse
    {
        $report = $this->reports->build(SimRun::query()->findOrFail($id));
        $before = $this->reports->build(SimRun::query()->findOrFail($baseline));

        return response()->json(['data' => [
            'totals' => ['p95_ms' => $report['totals']['p95_ms'] ?? 0, 'baseline_p95_ms' => $before['totals']['p95_ms'] ?? 0],
            'endpoints' => $this->reports->compare($report, $before),
        ]]);
    }

    /** @return array<string, mixed> */
    private function validatedScenario(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'config' => ['sometimes', 'array'],
        ]);

        if (isset($data['config'])) {
            $data['config'] = $this->validatedConfig($data['config']);
        }

        return $data;
    }

    /**
     * Only settings a scenario knows; the numbers are held to the caps when the run starts.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function validatedConfig(array $config): array
    {
        return validator($config, [
            'teams' => ['sometimes', 'integer', 'min:1'],
            'size_min' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'size_max' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'speed' => ['sometimes', 'integer', Rule::in((array) config('simulator.speeds'))],
            'duration_minutes' => ['sometimes', 'integer', 'min:1'],
            'day_mode' => ['sometimes', Rule::in(['realistic', 'workday', 'continuous'])],
            'ramp_up_seconds' => ['sometimes', 'integer', 'min:0'],
            'real_signup_share' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'volume' => ['sometimes', Rule::in(array_keys((array) config('simulator.volumes')))],
            'intensity' => ['sometimes', 'numeric', 'min:0.1', 'max:10'],
            'seed' => ['sometimes', 'integer', 'min:1'],
            'pack_id' => ['sometimes', 'nullable', 'integer'],
            'thresholds' => ['sometimes', 'array'],
            'thresholds.p95_ms' => ['sometimes', 'integer', 'min:1'],
            'thresholds.error_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'thresholds.flow_success' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'disabled_actions' => ['sometimes', 'array', 'max:60'],
            'disabled_actions.*' => ['string', 'max:40'],
            'personas' => ['sometimes', 'array', 'max:10'],
            'personas.*.pause' => ['sometimes', 'numeric', 'min:1', 'max:3600'],
            'personas.*.weights' => ['sometimes', 'array', 'max:60'],
            'personas.*.weights.*' => ['numeric', 'min:0', 'max:1000'],
            'ai' => ['sometimes', 'array'],
        ])->validate();
    }

    /** @return array<string, mixed> */
    private function presentScenario(SimScenario $scenario): array
    {
        return ['id' => $scenario->id, 'name' => $scenario->name, 'config' => $scenario->config, 'updated_at' => $scenario->updated_at];
    }

    /** @return array<string, mixed> */
    private function presentRun(SimRun $run): array
    {
        return [
            'id' => $run->id,
            'name' => $run->name,
            'status' => $run->status->value,
            'active' => $run->status->isActive(),
            'speed' => $run->speed,
            'seed' => $run->seed,
            'config' => $run->config,
            'verdict' => $run->verdict,
            'stop_reason' => $run->stop_reason,
            'totals' => $run->summary['totals'] ?? null,
            // Whether its workspaces and accounts are still there.
            'purged' => ! SimTeam::query()->where('run_id', $run->id)->exists(),
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
        ];
    }
}
