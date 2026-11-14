<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_comment')]
#[Description('Writes a comment on an item. With kind "question" it also pauses the item as needing input until a person replies; check back with list_activity.')]
class AddComment extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'body' => $schema->string()->description('Markdown')->required(),
            'kind' => $schema->string()->enum(['comment', 'question'])->description('Default comment'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $key = rawurlencode((string) $request->get('key'));
        $isQuestion = $request->get('kind') === 'question';

        $result = $isQuestion
            ? $this->api('POST', "items/{$key}/ask", ['question' => $request->get('body')])
            : $this->api('POST', "items/{$key}/comments", ['body' => $request->get('body')]);

        if (! $result->ok()) {
            return $this->failure($result);
        }

        return $this->result($isQuestion
            ? ['asked' => true, 'needs_input' => true, 'next' => 'Stop work on this item. The flag clears when a person replies; read the answer with list_activity.']
            : ['commented' => true, 'comment_id' => $result->data()['id']]);
    }
}
