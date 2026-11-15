<?php

namespace App\Simulator;

use App\Ai\AiCompletion;
use App\Ai\AiPrompt;
use App\Ai\AiProvider;

/**
 * Answers the built-in AI features for simulated workspaces without calling a
 * model: a plausible value for every field the prompt's schema asks for.
 */
class CannedAiProvider implements AiProvider
{
    public function complete(AiPrompt $prompt, string $apiKey): AiCompletion
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->fill($prompt->schema, $prompt->feature);

        return new AiCompletion($data, 0, 0, 'simulated');
    }

    /** @param  array<string, mixed>  $schema */
    private function fill(array $schema, string $name): mixed
    {
        $type = $schema['type'] ?? 'string';

        if (is_array($type)) {
            return in_array('null', $type, true) ? null : $this->fill(['type' => $type[0]] + $schema, $name);
        }

        if (isset($schema['enum'][0])) {
            return $schema['enum'][0];
        }

        return match ($type) {
            'object' => collect((array) ($schema['properties'] ?? []))->map(fn (array $sub, string $key) => $this->fill($sub, $key))->all(),
            'array' => isset($schema['items']['enum']) ? [] : [$this->fill((array) ($schema['items'] ?? []), $name), $this->fill((array) ($schema['items'] ?? []), $name)],
            'integer', 'number' => 1,
            'boolean' => true,
            default => 'Simulated '.str_replace('_', ' ', $name),
        };
    }
}
