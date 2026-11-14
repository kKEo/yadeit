<?php

namespace App\Ai;

final readonly class AiCompletion
{
    /** @param  array<string, mixed>  $data  The answer, already matching the prompt's schema. */
    public function __construct(
        public array $data,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
    ) {}
}
