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
use App\Simulator\SimRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What a runner talks to: it registers, is told which teams to play and how
 * fast, reads the mail of its simulated people, and reports back.
 */
class RunnerController extends Controller
{
    /** A runner that has not checked in for this long has its teams given to another. */
    private const STALE_SECONDS = 20;

    public function __construct(private SimRuns $runs) {}

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
        ]], 201);
    }

    /** The heartbeat. Answers with the state of the run and the teams this runner plays. */
    public function poll(Request $request): JsonResponse
    {
        $run = $this->run($request);
        $data = $request->validate(['runner_id' => ['required', 'integer'], 'lag_ms' => ['sometimes', 'integer', 'min:0']]);
        $runner = SimRunner::query()->where('run_id', $run->id)->findOrFail($data['runner_id']);
        $runner->update(['last_seen_at' => now(), 'lag_ms' => $data['lag_ms'] ?? 0]);

        $this->runs->enforceLimits($run);
        $run->refresh();

        if ($run->status->isActive()) {
            // Teams without a runner, or whose runner went silent, go to the one asking.
            $alive = SimRunner::query()->where('run_id', $run->id)->where('last_seen_at', '>=', now()->subSeconds(self::STALE_SECONDS))->pluck('id');
            DB::transaction(fn () => SimTeam::query()->where('run_id', $run->id)
                ->where(fn ($q) => $q->whereNull('runner_id')->orWhereNotIn('runner_id', $alive))
                ->lockForUpdate()->get()->each->update(['runner_id' => $runner->id]));
        }

        $teams = SimTeam::query()->where('run_id', $run->id)->where('runner_id', $runner->id)->orderBy('id')->get();
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
                'visitor_header' => $run->headerFor("v{$team->id}"),
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
