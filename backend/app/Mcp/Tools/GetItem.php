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

#[Name('get_item')]
#[Description('Everything about one item: description, acceptance criteria, links, relations, sub-items and the latest comments.')]
#[IsReadOnly]
#[IsIdempotent]
class GetItem extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'comments' => $schema->integer()->description('How many of the latest comments to include, 0-30, default 5'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $key = rawurlencode((string) $request->get('key'));
        $item = $this->api('GET', "items/{$key}");

        if (! $item->ok()) {
            return $this->failure($item);
        }

        $detail = $this->detail($item->data());
        $want = max(0, min(30, (int) $request->get('comments', 5)));

        if ($want > 0 && ($item->data()['comment_count'] ?? 0) > 0) {
            $comments = $this->api('GET', "items/{$key}/comments", ['limit' => 100]);
            $detail['latest_comments'] = array_map(
                fn (array $c) => ['by' => $c['author']['name'] ?? 'someone', 'at' => $c['created_at'], 'text' => $c['body']],
                array_slice($comments->data() ?? [], -$want),
            );
        }

        return $this->result([...$detail, 'note' => self::UNTRUSTED]);
    }
}
