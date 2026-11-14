<?php

namespace App\Mcp;

class ApiResult
{
    /** @param  array<string, mixed>  $body */
    public function __construct(public readonly int $status, public readonly array $body) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function data(): mixed
    {
        return $this->body['data'] ?? null;
    }

    /** The API's explanation of a failure, in one line an agent can act on. */
    public function error(): string
    {
        $error = $this->body['error'] ?? [];
        $text = ($error['message'] ?? 'The request failed.').' ['.($error['code'] ?? $this->status).']';

        $fields = $error['details']['fields'] ?? [];

        // A single field problem is already the message; list them only when there are several.
        if (count($fields) > 1) {
            foreach ($fields as $field => $messages) {
                $text .= "\n- {$field}: ".implode(' ', (array) $messages);
            }
        }

        $extra = array_diff_key($error['details'] ?? [], ['fields' => true]);

        return $extra === [] ? $text : $text."\n".json_encode($extra);
    }
}
