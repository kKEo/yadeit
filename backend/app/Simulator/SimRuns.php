<?php

namespace App\Simulator;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Simulator\Actions\ProvisionSimTeam;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimTeam;
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
    ];

    public function __construct(private ProvisionSimTeam $provision) {}

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
            $team = SimTeam::create([
                'run_id' => $run->id,
                'name' => ($names[($number - 1) % max(1, count($names))] ?? 'Team')." {$run->id}-{$number}",
                'size' => $random->getInt($config['size_min'], $config['size_max']),
                'onboarding' => $number <= $real ? 'real' : 'fast',
            ]);

            $this->provision->handle($run, $team, $number);
        }

        $run->update(['status' => SimRunStatus::Running, 'started_at' => now()]);

        return [$run, "{$run->id}|{$token}"];
    }

    public function stop(SimRun $run, string $reason, SimRunStatus $as = SimRunStatus::Finished): SimRun
    {
        if ($run->status->isActive()) {
            $run->update(['status' => $as, 'stop_reason' => mb_substr($reason, 0, 200), 'finished_at' => now()]);
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

    /** Ends the run when its time is up. Called whenever a runner checks in. */
    public function enforceLimits(SimRun $run): void
    {
        $minutes = (int) data_get($run->config, 'duration_minutes');

        if ($run->status->isActive() && $run->started_at !== null && $run->started_at->copy()->addMinutes($minutes)->isPast()) {
            $this->stop($run, "Ran for its {$minutes} minutes.");
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

        return $config;
    }
}
