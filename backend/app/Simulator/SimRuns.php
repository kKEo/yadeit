<?php

namespace App\Simulator;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Simulator\Actions\ProvisionSimTeam;
use App\Simulator\Models\SimMetricBucket;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimRunner;
use App\Simulator\Models\SimTeam;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Starting and ending runs. The admin panel and the command line both go through here. */
class SimRuns
{
    /** What a run is made of when the scenario does not say. */
    public const DEFAULTS = [
        'teams' => 3,
        'size_min' => 2,
        'size_max' => 6,
        'speed' => 10,
        'duration_minutes' => 10,
        // realistic (quiet nights), workday (office hours only) or continuous.
        'day_mode' => 'workday',
        // Teams join one after another over this many seconds, so the run does not start with a stampede.
        'ramp_up_seconds' => 30,
        // Share of teams (0-1) whose members sign up and invite each other through the API.
        'real_signup_share' => 0.34,
        'volume' => 'small',
        // Multiplies how often every member acts.
        'intensity' => 1.0,
        'seed' => 1,
        'pack_id' => null,
        // When to give up by itself: this share of requests failing over a minute, or no runner heard for this long.
        'stop' => ['error_rate' => 0.5, 'runner_silence_seconds' => 90],
        // What the verdict is measured against (see SimReport::THRESHOLDS).
        'thresholds' => [],
    ];

    public function __construct(private ProvisionSimTeam $provision, private SimReport $reports) {}

    /**
     * Creates the run with its teams and returns it with the token its runners sign in with.
     * The token is shown once; only its hash is kept.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: SimRun, 1: string}
     */
    public function start(array $config, ?User $by = null, string $name = 'Run'): array
    {
        if (! Simulator::enabled()) {
            throw new ApiException(404, 'not_found', 'The Traffic lab is switched off.');
        }

        if ($this->active() !== null) {
            throw new ApiException(409, 'conflict', 'A run is already going on. Stop it first.');
        }

        $config = $this->normalise($config);
        $token = Str::random(48);

        $run = SimRun::create([
            'name' => $name,
            'status' => SimRunStatus::Provisioning,
            'config' => $config,
            'speed' => $config['speed'],
            'seed' => $config['seed'],
            'runner_token_hash' => hash('sha256', $token),
            'created_by' => $by?->id,
        ]);

        $random = new Randomizer(new Mt19937($run->seed & 0x7FFFFFFF));
        $names = $random->shuffleArray(ContentPack::load($config['pack_id'])->list('teams'));
        $real = (int) round($config['teams'] * $config['real_signup_share']);

        for ($number = 1; $number <= $config['teams']; $number++) {
            $this->createTeam($run, $number, $names, $random, $number <= $real ? 'real' : 'fast');
        }

        $run->update(['status' => SimRunStatus::Running, 'started_at' => now()]);

        return [$run, "{$run->id}|{$token}"];
    }

    /**
     * @param  list<mixed>  $names
     */
    private function createTeam(SimRun $run, int $number, array $names, Randomizer $random, string $onboarding): SimTeam
    {
        $team = SimTeam::create([
            'run_id' => $run->id,
            'name' => ($names[($number - 1) % max(1, count($names))] ?? 'Team')." {$run->id}-{$number}",
            'size' => $random->getInt((int) $run->config['size_min'], (int) $run->config['size_max']),
            'onboarding' => $onboarding,
        ]);

        return $this->provision->handle($run, $team, $number);
    }

    /** More teams join a run that is going on. They are created directly, so they are in at once. */
    public function addTeams(SimRun $run, int $count): SimRun
    {
        $this->mustBeActive($run);
        $have = SimTeam::query()->where('run_id', $run->id)->count();
        $playing = SimTeam::query()->where('run_id', $run->id)->where('status', '!=', 'removed')->count();
        $count = max(0, min($count, (int) config('simulator.caps.teams') - $playing));
        $names = ContentPack::load(data_get($run->config, 'pack_id'))->list('teams');

        for ($number = $have + 1; $number <= $have + $count; $number++) {
            $this->createTeam($run, $number, $names, new Randomizer(new Mt19937(($run->seed + $number) & 0x7FFFFFFF)), 'fast');
        }

        return $run;
    }

    /** The newest teams leave the run. Their workspaces stay until the run is purged. */
    public function removeTeams(SimRun $run, int $count): SimRun
    {
        $this->mustBeActive($run);
        $playing = SimTeam::query()->where('run_id', $run->id)->where('status', '!=', 'removed')->orderByDesc('id');
        // A run keeps at least one team.
        $count = max(0, min($count, (clone $playing)->count() - 1));
        (clone $playing)->limit($count)->get()->each->update(['status' => 'removed', 'runner_id' => null]);

        return $run;
    }

    public function setSpeed(SimRun $run, int $speed): SimRun
    {
        $this->mustBeActive($run);
        $speed = $this->normalise(['speed' => $speed])['speed'];
        $run->update(['speed' => $speed, 'config' => ['speed' => $speed] + $run->config]);

        return $run;
    }

    /** A paused run stands still: simulated time stops and nobody acts. Its time limit keeps counting. */
    public function pause(SimRun $run, bool $paused): SimRun
    {
        $this->mustBeActive($run);
        $run->update(['status' => $paused ? SimRunStatus::Paused : SimRunStatus::Running]);

        return $run;
    }

    private function mustBeActive(SimRun $run): void
    {
        if (! $run->status->isActive()) {
            throw new ApiException(409, 'conflict', 'This run has ended.');
        }
    }

    public function stop(SimRun $run, string $reason, SimRunStatus $as = SimRunStatus::Finished): SimRun
    {
        if ($run->status->isActive()) {
            $run->update(['status' => $as, 'stop_reason' => mb_substr($reason, 0, 200), 'finished_at' => now()]);
            $this->reports->save($run);
        }

        return $run;
    }

    public function active(): ?SimRun
    {
        return SimRun::query()->whereIn('status', array_map(
            fn (SimRunStatus $s) => $s->value,
            array_filter(SimRunStatus::cases(), fn (SimRunStatus $s) => $s->isActive()),
        ))->orderByDesc('id')->first();
    }

    /**
     * Ends the run when a stop rule applies: its time is up, the installation
     * is failing, its runners are gone, or the AI budget is spent. Called
     * whenever a runner checks in or reports, and once a minute by the scheduler.
     */
    public function enforceLimits(SimRun $run): void
    {
        if (! $run->status->isActive() || $run->started_at === null) {
            return;
        }

        $minutes = (int) data_get($run->config, 'duration_minutes');

        if ($run->started_at->copy()->addMinutes($minutes)->isPast()) {
            $this->stop($run, "Ran for its {$minutes} minutes.");

            return;
        }

        $recent = SimMetricBucket::query()->where('run_id', $run->id)->where('kind', 'endpoint')->where('bucket_at', '>=', now()->subSeconds(60));
        $requests = (int) (clone $recent)->sum('count');
        $limit = (float) data_get($run->config, 'stop.error_rate', 0.5);

        if ($requests >= 50 && (int) (clone $recent)->sum('errors') / $requests > $limit) {
            $this->stop($run, sprintf('More than %d%% of requests failed over the last minute.', $limit * 100), SimRunStatus::Aborted);

            return;
        }

        $silence = (int) data_get($run->config, 'stop.runner_silence_seconds', 90);
        $lastSeen = SimRunner::query()->where('run_id', $run->id)->max('last_seen_at');

        if ($lastSeen !== null && Carbon::parse($lastSeen)->addSeconds($silence)->isPast()) {
            $this->stop($run, "No runner has been heard for {$silence} seconds.", SimRunStatus::Failed);

            return;
        }

        $budget = (int) data_get($run->config, 'ai.token_budget', 0);

        if ($budget > 0 && $run->ai_tokens_used >= $budget) {
            $this->stop($run, 'The AI token budget of the run is spent.');
        }
    }

    /**
     * Fills in defaults and holds every number to the caps of the installation.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function normalise(array $config): array
    {
        $config = array_replace(self::DEFAULTS, $config);
        $caps = (array) config('simulator.caps');
        $speeds = array_filter((array) config('simulator.speeds'), fn (int $s) => $s <= $caps['speed']);

        $config['teams'] = max(1, min((int) $caps['teams'], (int) $config['teams']));
        $config['size_min'] = max(1, min((int) $caps['team_size'], (int) $config['size_min']));
        $config['size_max'] = max($config['size_min'], min((int) $caps['team_size'], (int) $config['size_max']));
        $config['speed'] = in_array((int) $config['speed'], $speeds, true) ? (int) $config['speed'] : 1;
        $config['duration_minutes'] = max(1, min((int) $caps['duration_minutes'], (int) $config['duration_minutes']));
        $config['day_mode'] = in_array($config['day_mode'], ['realistic', 'workday', 'continuous'], true) ? $config['day_mode'] : 'workday';
        $config['ramp_up_seconds'] = max(0, min(3600, (int) $config['ramp_up_seconds']));
        $config['real_signup_share'] = max(0.0, min(1.0, (float) $config['real_signup_share']));
        $config['volume'] = array_key_exists((string) $config['volume'], (array) config('simulator.volumes')) ? (string) $config['volume'] : 'small';
        $config['intensity'] = max(0.1, min(10.0, (float) $config['intensity']));
        $config['seed'] = max(1, (int) $config['seed']);
        $config['pack_id'] = $config['pack_id'] === null ? null : (int) $config['pack_id'];
        $config['thresholds'] = array_intersect_key((array) $config['thresholds'], SimReport::THRESHOLDS);
        // Actions and flows a scenario switches off, and its own weights and pauses per persona; the runner applies them.
        $config['disabled_actions'] = array_values(array_filter((array) ($config['disabled_actions'] ?? []), 'is_string'));
        $config['personas'] = (array) ($config['personas'] ?? []);

        return $config;
    }
}
