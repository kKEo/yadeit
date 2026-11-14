<?php

// What each plan allows. null means no limit. Agents never count as members.
return [
    'default' => 'free',

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
            'stripe_price' => env('STRIPE_PRICE_TEAM'),
        ],
    ],
];
