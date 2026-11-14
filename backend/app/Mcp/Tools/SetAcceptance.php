<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('set_acceptance')]
#[Description('Edits the acceptance criteria (checklist) of an item: add entries, and tick or untick entries by the ids get_item shows.')]
#[IsIdempotent]
class SetAcceptance extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'add' => $schema->array()->items($schema->string())->description('New criteria'),
            'check' => $schema->array()->items($schema->integer())->description('Ids to mark done'),
            'uncheck' => $schema->array()->items($schema->integer())->description('Ids to mark not done'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $key = rawurlencode((string) $request->get('key'));
        $calls = [];

        foreach ((array) $request->get('add', []) as $text) {
            $calls[] = ['POST', "items/{$key}/checklist", ['text' => (string) $text]];
        }

        foreach ((array) $request->get('check', []) as $id) {
            $calls[] = ['PATCH', "items/{$key}/checklist/".(int) $id, ['is_done' => true]];
        }

        foreach ((array) $request->get('uncheck', []) as $id) {
            $calls[] = ['PATCH', "items/{$key}/checklist/".(int) $id, ['is_done' => false]];
        }

        if ($calls === []) {
            return Response::error('Nothing to do: pass add, check or uncheck.');
        }

        foreach ($calls as [$method, $path, $data]) {
            $result = $this->api($method, $path, $data);

            if (! $result->ok()) {
                return $this->failure($result);
            }
        }

        $item = $this->api('GET', "items/{$key}");

        return $item->ok() ? $this->result(['acceptance' => $this->detail($item->data())['acceptance'] ?? []]) : $this->failure($item);
    }
}
