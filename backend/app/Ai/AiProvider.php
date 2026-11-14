<?php

namespace App\Ai;

/** Whatever answers prompts. Production uses Anthropic; tests swap in a fake so no request leaves the machine. */
interface AiProvider
{
    public function complete(AiPrompt $prompt, string $apiKey): AiCompletion;
}
