<?php

namespace App\Simulator\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Simulator\ContentPack;
use App\Simulator\Models\SimMail;
use App\Simulator\Models\SimMember;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimRunner;
use App\Simulator\Models\SimTeam;
use App\Simulator\SimCalendar;
use App\Simulator\SimMetrics;
use App\Simulator\SimReport;
use App\Simulator\SimRuns;
use App\Simulator\Simulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What a runner talks to: it registers, is told which teams to play and how
 * fast, reads the mail of its simulated people, and reports back.
 */
class RunnerController extends Controller
{
    /** A runner that has not checked in for this long has its teams given to another. */
    private const STALE_SECONDS = 20;

    public function __construct(private SimRuns $runs, private SimMetrics $metrics, private SimReport $reports, private SimCalendar $calendar) {}

    public function register(Request $request): JsonResponse
    {
        $run = $this->run($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'host' => ['sometimes', 'nullable', 'string', 'max:120']]);

        $runner = SimRunner::create(['run_id' => $run->id, 'name' => $data['name'], 'host' => $data['host'] ?? null, 'last_seen_at' => now()]);

        return response()->json(['data' => [
            'runner_id' => $runner->id,
            'run' => $this->presentRun($run),
            // Requests of simulated people must look like the web app's, or they get no session.
            'origin' => rtrim((string) config('app.frontend_url'), '/'),
            'pack' => ContentPack::load(data_get($run->config, 'pack_id'))->content,
            // Runners sort latencies into these buckets, so that their numbers add up.
            'histogram_bounds' => SimMetrics::BOUNDS,
            'step_seconds' => SimMetrics::STEP_SECONDS,
            'sink_url' => Simulator::sinkUrl(),
            // Explorers think for seconds per step; above this speed they simply act less often.
            'explorer_speed_cap' => (int) config('simulator.ai.explorer_speed_cap'),
            // Where members open their websocket, as the web app does; null when the installation has realtime off.
            'realtime' => config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key')) ? [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ] : null,
        ]], 201);
    }

    /** The heartbeat. Answers with the state of the run and the teams this runner plays. */
    public function poll(Request $request): JsonResponse
    {
        $run = $this->run($request);
        $data = $request->validate([
            'runner_id' => ['required', 'integer'], 'lag_ms' => ['sometimes', 'integer', 'min:0'],
            // What each member is doing right now, for the live screen.
            'activity' => ['sometimes', 'array', 'max:2000'], 'activity.*' => ['string', 'max:40'],
            // The simulated day the runner has reached; a new day plays the night before it (cycles, stale items).
            'sim_day' => ['sometimes', 'integer', 'min:0'],
        ]);
        $runner = SimRunner::query()->where('run_id', $run->id)->findOrFail($data['runner_id']);
        $runner->update(['last_seen_at' => now(), 'lag_ms' => $data['lag_ms'] ?? 0, 'meta' => ['activity' => $data['activity'] ?? []]]);

        $this->runs->enforceLimits($run);
        $run->refresh();

        if ($run->status->isActive() && ($data['sim_day'] ?? 0) > $run->sim_day) {
            $this->calendar->advanceTo($run, (int) $data['sim_day']);
        }

        if ($run->status->isActive()) {
            // Teams without a runner, or whose runner went silent, go to the one asking.
            $alive = SimRunner::query()->where('run_id', $run->id)->where('last_seen_at', '>=', now()->subSeconds(self::STALE_SECONDS))->pluck('id');
            DB::transaction(fn () => SimTeam::query()->where('run_id', $run->id)->where('status', '!=', 'removed')
                ->where(fn ($q) => $q->whereNull('runner_id')->orWhereNotIn('runner_id', $alive))
                ->lockForUpdate()->get()->each->update(['runner_id' => $runner->id]));
        }

        $teams = SimTeam::query()->where('run_id', $run->id)->where('runner_id', $runner->id)->where('status', '!=', 'removed')->orderBy('id')->get();
        $members = SimMember::query()->whereIn('team_id', $teams->pluck('id'))->orderBy('id')->get()->groupBy('team_id');
        $slugs = DB::table('workspaces')->whereIn('id', $teams->pluck('workspace_id')->filter())->pluck('slug', 'id');

        return response()->json(['data' => [
            'run' => $this->presentRun($run),
            'teams' => $teams->map(fn (SimTeam $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'onboarding' => $team->onboarding,
                'status' => $team->status,
                'workspace' => $slugs[$team->workspace_id] ?? null,
                // People who are not signed in: each visit is someone else.
                'visitor_headers' => array_map(fn (int $n) => $run->headerFor("v{$team->id}x{$n}"), range(1, 10)),
                'members' => ($members[$team->id] ?? collect())->map(fn (SimMember $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'persona' => $m->persona,
                    'role' => $m->role,
                    'kind' => $m->kind,
                    'email' => $m->email,
                    'password' => $m->password,
                    'header' => $run->headerFor("m{$m->id}"),
                ])->values(),
            ])->values(),
        ]]);
    }

    /** The newest kept message to one simulated person: the links of a confirmation, an invitation or a password reset. */
    public function mailbox(Request $request): JsonResponse
    {
        $run = $this->run($request);
        $data = $request->validate(['email' => ['required', 'string'], 'kind' => ['required', 'string']]);

        $mail = SimMail::query()->where('run_id', $run->id)->where('to_email', mb_strtolower($data['email']))
            ->where('kind', $data['kind'])->orderByDesc('id')->first();

        return response()->json(['data' => $mail === null ? null : ['subject' => $mail->subject, 'links' => $mail->links, 'created_at' => $mail->created_at]]);
    }

    /** What a runner measured since it last reported, and what its checks found wrong. */
    public function metrics(Request $request): JsonResponse
    {
        $run = $this->run($request);
        $size = count(SimMetrics::BOUNDS) + 1;

        $data = $request->validate([
            'buckets' => ['present', 'array', 'max:5000'],
            'buckets.*.at' => ['required', 'integer', 'min:0'],
            'buckets.*.kind' => ['required', Rule::in(SimMetrics::KINDS)],
            'buckets.*.name' => ['required', 'string', 'max:120'],
            'buckets.*.count' => ['required', 'integer', 'min:0'],
            'buckets.*.errors' => ['sometimes', 'integer', 'min:0'],
            'buckets.*.sum_ms' => ['sometimes', 'numeric', 'min:0'],
            'buckets.*.max_ms' => ['sometimes', 'numeric', 'min:0'],
            'buckets.*.histogram' => ['sometimes', 'array', "size:{$size}"],
            'buckets.*.histogram.*' => ['integer', 'min:0'],
            'buckets.*.extra' => ['sometimes', 'array', 'max:8'],
            'buckets.*.extra.*' => ['numeric'],
            'findings' => ['sometimes', 'array', 'max:200'],
            'findings.*.flow' => ['required', 'string', 'max:40'],
            'findings.*.step' => ['sometimes', 'nullable', 'string', 'max:60'],
            'findings.*.severity' => ['sometimes', Rule::in(['error', 'warning', 'note'])],
            'findings.*.message' => ['required', 'string', 'max:2000'],
            'findings.*.team_id' => ['sometimes', 'nullable', 'integer'],
            'findings.*.member_id' => ['sometimes', 'nullable', 'integer'],
            'findings.*.details' => ['sometimes', 'nullable', 'array'],
        ]);

        $this->metrics->ingest($run, $data['buckets'], $data['findings'] ?? []);
        $this->runs->enforceLimits($run);

        // What arrives after the end still belongs in the report.
        if (! $run->refresh()->status->isActive()) {
            $this->reports->save($run);
        }

        return response()->json(['data' => ['status' => $run->status->value]]);
    }

    /** A team that signed up by itself reports that it exists; the run then knows its workspace and accounts. */
    public function teamReady(Request $request, int $team): JsonResponse
    {
        $run = $this->run($request);
        $model = SimTeam::query()->where('run_id', $run->id)->findOrFail($team);
        $members = SimMember::query()->where('team_id', $model->id)->get();
        $users = User::query()->where('sim_run_id', $run->id)->whereIn('email', $members->pluck('email'))->pluck('id', 'email');

        $members->each(fn (SimMember $m) => $m->update(['user_id' => $users[$m->email] ?? null]));

        $owner = $members->firstWhere('role', 'owner');
        $workspaceId = $owner === null ? null : DB::table('workspaces')->where('sim_run_id', $run->id)
            ->where('created_by', $users[$owner->email] ?? 0)->orderBy('id')->value('id');

        $model->update(['workspace_id' => $workspaceId, 'status' => $workspaceId === null ? 'awaiting_signup' : 'ready']);

        return response()->json(['data' => ['status' => $model->status]]);
    }

    private function run(Request $request): SimRun
    {
        /** @var SimRun */
        return $request->attributes->get('sim_run');
    }

    /** @return array<string, mixed> */
    private function presentRun(SimRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'speed' => $run->speed,
            'seed' => $run->seed,
            'config' => $run->config,
            'started_at' => $run->started_at,
            'stop_reason' => $run->stop_reason,
        ];
    }
}
