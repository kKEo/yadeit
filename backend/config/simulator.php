<?php

// The Traffic lab: simulated teams that use the installation like real ones, to measure it.
// See docs/traffic-lab.md. Everything here is off until SIMULATOR_ENABLED is set.
return [
    'enabled' => (bool) env('SIMULATOR_ENABLED', false),

    // In production a second switch is needed, so that one stray variable cannot start a load test on real users.
    'allow_production' => (bool) env('SIMULATOR_ALLOW_PRODUCTION', false),

    // A runner that is always running signs in with "standby|<this>" and plays whichever run is started from the admin panel.
    // Empty means no standing runner: each run then prints a token of its own.
    'runner_token' => env('SIMULATOR_RUNNER_TOKEN', ''),

    // Simulated people have addresses in this domain. ".invalid" can never receive mail, and mail to it is never sent anyway.
    'mail_domain' => env('SIMULATOR_MAIL_DOMAIN', 'sim.invalid'),

    // Hard limits, whatever a scenario asks for.
    'caps' => [
        'teams' => (int) env('SIMULATOR_MAX_TEAMS', 50),
        'team_size' => 10,
        'speed' => (int) env('SIMULATOR_MAX_SPEED', 100),
        'duration_minutes' => (int) env('SIMULATOR_MAX_DURATION_MINUTES', 240),
    ],

    'speeds' => [1, 2, 5, 10, 50, 100],

    // Finished runs and everything they created are removed after this many days.
    'retention_days' => (int) env('SIMULATOR_RETENTION_DAYS', 14),

    // Items seeded into each workspace before the run starts.
    'volumes' => ['empty' => 0, 'small' => 30, 'medium' => 200, 'large' => 1000],

    'ai' => [
        'model' => env('SIMULATOR_AI_MODEL', 'claude-haiku-5-5'),
        'token_budget' => (int) env('SIMULATOR_AI_TOKEN_BUDGET', 500_000),
        'max_explorers' => 3,
        'explorer_speed_cap' => 5,
    ],

    // Who is in a team of each size: persona and workspace role. Agents join through the API once the run starts.
    'templates' => [
        1 => [['lead', 'owner']],
        2 => [['lead', 'owner'], ['developer', 'member']],
        3 => [['lead', 'owner'], ['developer', 'member'], ['developer', 'member']],
        4 => [['lead', 'owner'], ['developer', 'member'], ['developer', 'member'], ['designer', 'member']],
        5 => [['lead', 'owner'], ['developer', 'member'], ['developer', 'member'], ['designer', 'member'], ['client', 'guest']],
        6 => [['lead', 'owner'], ['lead', 'admin'], ['developer', 'member'], ['developer', 'member'], ['designer', 'member'], ['client', 'guest']],
        7 => [['lead', 'owner'], ['lead', 'admin'], ['developer', 'member'], ['developer', 'member'], ['designer', 'member'], ['client', 'guest'], ['agent', 'member']],
        8 => [['lead', 'owner'], ['lead', 'admin'], ['developer', 'member'], ['developer', 'member'], ['developer', 'member'], ['designer', 'member'], ['client', 'guest'], ['agent', 'member']],
        9 => [['lead', 'owner'], ['lead', 'admin'], ['developer', 'member'], ['developer', 'member'], ['developer', 'member'], ['designer', 'member'], ['client', 'guest'], ['client', 'guest'], ['agent', 'member']],
        10 => [['lead', 'owner'], ['lead', 'admin'], ['developer', 'member'], ['developer', 'member'], ['developer', 'member'], ['designer', 'member'], ['client', 'guest'], ['client', 'guest'], ['agent', 'member'], ['agent', 'member']],
    ],
];
