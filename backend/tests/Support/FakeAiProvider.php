<?php

namespace Tests\Support;

use App\Ai\AiCompletion;
use App\Ai\AiPrompt;
use App\Ai\AiProvider;

/** Stands in for the model provider: records every prompt it is given and answers with what the test queued. */
class FakeAiProvider implements AiProvider
{
    /** @var list<AiPrompt> */
    public array $prompts = [];

    /** @var list<string> */
    public array $keys = [];

    /** @var array<string, mixed> */
    public array $answer = [];

    public function complete(AiPrompt $prompt, string $apiKey): AiCompletion
    {
        $this->prompts[] = $prompt;
        $this->keys[] = $apiKey;

        return new AiCompletion($this->answer, 1200, 300, 'claude-opus-5-5');
    }
}
