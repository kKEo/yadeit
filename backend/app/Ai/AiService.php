<?php

namespace App\Ai;

use App\Exceptions\ApiException;
use App\Models\AiUsage;
use App\Models\User;
use App\Models\Workspace;

/**
 * The only way the application talks to a model. It refuses before any
 * request is made when the workspace has AI switched off, has no key to use,
 * or has used up its month; and it writes down what every call cost.
 */
class AiService
{
    public function __construct(private AiProvider $provider) {}

    /** @return array<string, mixed> the model's answer */
    public function run(Workspace $workspace, User $user, AiPrompt $prompt): array
    {
        if (! $workspace->ai_enabled) {
            throw new ApiException(403, 'ai_disabled', 'AI features are switched off for this workspace. An admin can switch them on in the settings.');
        }

        $key = $this->key($workspace);

        if ($key === null) {
            throw new ApiException(503, 'ai_not_configured', 'No AI provider key is configured. An admin can add one in the workspace settings.');
        }

        $status = $this->status($workspace);

        if ($status['used_tokens'] >= $status['monthly_token_limit']) {
            throw new ApiException(429, 'ai_limit_reached', 'This workspace has used its AI allowance for the month. It resets on the 1st.', [
                'used_tokens' => $status['used_tokens'], 'monthly_token_limit' => $status['monthly_token_limit'],
            ]);
        }

        $completion = $this->provider->complete($prompt, $key);

        AiUsage::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'feature' => $prompt->feature,
            'model' => $completion->model,
            'input_tokens' => $completion->inputTokens,
            'output_tokens' => $completion->outputTokens,
            'created_at' => now(),
        ]);

        return $completion->data;
    }

    /** @return array{enabled: bool, configured: bool, has_own_key: bool, used_tokens: int, monthly_token_limit: int, model: string} */
    public function status(Workspace $workspace): array
    {
        $used = AiUsage::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE(SUM(input_tokens), 0) + COALESCE(SUM(output_tokens), 0) AS total')
            ->value('total');

        return [
            'enabled' => (bool) $workspace->ai_enabled,
            'configured' => $this->key($workspace) !== null,
            'has_own_key' => $workspace->ai_api_key !== null,
            'used_tokens' => (int) $used,
            'monthly_token_limit' => (int) ($workspace->ai_monthly_token_limit ?? config('ai.monthly_token_limit')),
            'model' => (string) config('ai.model'),
        ];
    }

    private function key(Workspace $workspace): ?string
    {
        $key = $workspace->ai_api_key ?? config('ai.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
