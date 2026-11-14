<?php

namespace App\Ai;

/** One request to the model: what to do, the material to do it on, and the shape the answer must have. */
final readonly class AiPrompt
{
    /**
     * @param  string  $feature  Name used for metering, e.g. "draft_item".
     * @param  string  $system  The task and its rules; written by us, never contains user text.
     * @param  string  $content  The material: user-written text, wrapped in tags by the caller.
     * @param  array<string, mixed>  $schema  JSON Schema of the answer.
     */
    public function __construct(
        public string $feature,
        public string $system,
        public string $content,
        public array $schema,
    ) {}
}
