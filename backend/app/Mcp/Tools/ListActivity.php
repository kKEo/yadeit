<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_activity')]
#[Description('What happened on an item, newest first: comments with their text and other changes in brief. Use it to read a person\'s answer to your question.')]
#[IsReadOnly]
#[IsIdempotent]
class ListActivity extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'limit' => $schema->integer()->description('1-50, default 15'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $key = rawurlencode((string) $request->get('key'));
        $limit = max(1, min(50, (int) $request->get('limit', 15)));
        $activity = $this->api('GET', "items/{$key}/activity", ['limit' => $limit]);

        if (! $activity->ok()) {
            return $this->failure($activity);
        }

        $comments = array_column((array) $this->api('GET', "items/{$key}/comments", ['limit' => 100])->data(), null, 'id');
        $item = $this->api('GET', "items/{$key}")->data() ?? [];

        $entries = array_map(function (array $entry) use ($comments): array {
            $changes = $entry['changes'] ?? [];
            $comment = isset($changes['comment_id']) ? ($comments[$changes['comment_id']] ?? null) : null;
            unset($changes['comment_id']);

            return array_filter([
                'at' => $entry['created_at'],
                'by' => $entry['actor']['name'] ?? 'someone',
                'event' => $entry['event'],
                'text' => $comment['body'] ?? null,
                'changes' => $changes ?: null,
            ], fn (mixed $v) => $v !== null);
        }, $activity->data());

        return $this->result([
            'key' => $item['key'] ?? $request->get('key'),
            'state' => $item['state']['name'] ?? null,
            'needs_input' => $item['needs_input'] ?? false,
            'activity' => $entries,
            'note' => self::UNTRUSTED,
        ]);
    }
}
