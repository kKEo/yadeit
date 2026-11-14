<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('link')]
#[Description('Attaches a web link (pull request, branch, design, any URL) to an item, or relates it to another item. Pass url, or relation with item.')]
#[IsIdempotent]
class Link extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'url' => $schema->string()->description('http(s) URL to attach'),
            'kind' => $schema->string()->enum(['pr', 'branch', 'design', 'url'])->description('What the URL is; default url'),
            'title' => $schema->string()->description('Link text'),
            'relation' => $schema->string()->enum(['blocks', 'blocked_by', 'relates', 'duplicate'])->description('How this item relates to the other'),
            'item' => $schema->string()->description('Key of the other item'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $key = (string) $request->get('key');
        $done = [];

        if ($request->get('url')) {
            $result = $this->api('POST', 'items/'.rawurlencode($key).'/links', $this->only($request, ['url', 'kind', 'title']));

            if (! $result->ok()) {
                return $this->failure($result);
            }

            $done['linked'] = $request->get('url');
        }

        if ($request->get('relation')) {
            $other = (string) $request->get('item');
            $relation = (string) $request->get('relation');

            // "A blocked_by B" is stored as "B blocks A".
            $result = $relation === 'blocked_by'
                ? $this->api('POST', 'items/'.rawurlencode($other).'/relations', ['type' => 'blocks', 'item' => $key])
                : $this->api('POST', 'items/'.rawurlencode($key).'/relations', ['type' => $relation, 'item' => $other]);

            if (! $result->ok()) {
                return $this->failure($result);
            }

            $done['related'] = "{$key} {$relation} {$other}";
        }

        return $done === []
            ? Response::error('Nothing to do: pass url, or relation together with item.')
            : $this->result($done);
    }
}
