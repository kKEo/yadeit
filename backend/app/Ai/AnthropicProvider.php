<?php

namespace App\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Exceptions\ApiException;

/**
 * Answers prompts with a Claude model through the official SDK.
 *
 * The answer is constrained to the prompt's JSON Schema (structured outputs),
 * so it can be decoded without guessing. If the model declines a request, the
 * API retries it on its default fallback model (server-side fallback).
 */
class AnthropicProvider implements AiProvider
{
    public function complete(AiPrompt $prompt, string $apiKey): AiCompletion
    {
        $client = new Client(apiKey: $apiKey);

        try {
            $message = $client->beta->messages->create(
                model: (string) config('ai.model'),
                maxTokens: (int) config('ai.max_output_tokens'),
                system: $prompt->system,
                messages: [['role' => 'user', 'content' => $prompt->content]],
                outputConfig: [
                    'effort' => (string) config('ai.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => $prompt->schema],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new ApiException(502, 'ai_key_rejected', 'The AI provider rejected the API key. An admin should check it in the workspace settings.');
        } catch (RateLimitException $e) {
            throw new ApiException(503, 'ai_busy', 'The AI provider is rate limiting requests right now. Try again in a minute.');
        } catch (APIStatusException $e) {
            throw new ApiException($e->status >= 500 ? 503 : 502, 'ai_failed', 'The AI provider could not answer (HTTP '.$e->status.'). Try again later.');
        } catch (APIConnectionException $e) {
            throw new ApiException(503, 'ai_unreachable', 'The AI provider could not be reached. Try again later.');
        }

        if ($message->stopReason === 'refusal') {
            throw new ApiException(422, 'ai_declined', 'The AI model declined this request. Nothing was changed; do this one by hand.');
        }

        if ($message->stopReason === 'max_tokens') {
            throw new ApiException(502, 'ai_incomplete', 'The answer was cut off because it was too long. Try with less text.');
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }

        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new ApiException(502, 'ai_failed', 'The AI provider returned an answer that could not be read. Try again.');
        }

        return new AiCompletion($data, $message->usage->inputTokens, $message->usage->outputTokens, (string) $message->model);
    }
}
