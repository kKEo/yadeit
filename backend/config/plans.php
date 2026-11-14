<?php

// What each plan allows. null means no limit. Agents never count as members.
return [
    'default' => 'free',

    // Limits apply only when this is on. A self-hosted installation without
    // paid plans leaves it off and nothing is held back.
    'enforce' => (bool) env('PLAN_LIMITS', false),

    'plans' => [
        'free' => [
            'name' => 'Free',
            'members' => 5,
            'projects' => 3,
            'ai_tokens' => 200_000,
            'stripe_price' => null,
        ],
        'team' => [
            'name' => 'Team',
            'members' => 50,
            'projects' => null,
            'ai_tokens' => 2_000_000,
            // The id of a recurring price in Stripe (price_…). One price per workspace, not per seat.
            'stripe_price' => env('STRIPE_PRICE_TEAM'),
        ],
    ],
];
