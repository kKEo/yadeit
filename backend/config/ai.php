<?php

return [
    // The instance's Anthropic API key. A workspace may also bring its own key.
    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('AI_MODEL', 'claude-opus-5-5'),

    // How hard the model thinks: low, medium, high, xhigh or max. The built-in
    // features are short drafting and sorting tasks, which do well at low.
    'effort' => env('AI_EFFORT', 'low'),

    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 16000),

    // Input plus output tokens a workspace may use per calendar month, unless it sets its own number.
    'monthly_token_limit' => (int) env('AI_MONTHLY_TOKEN_LIMIT', 2_000_000),
];
