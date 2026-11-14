<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('claim_item')]
#[Description('Takes an item to work on: you become its agent and it moves to the first started state. Fails if someone else holds it or it is blocked. With release true, gives it back instead.')]
#[IsIdempotent]
class ClaimItem extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'release' => $schema->boolean()->description('true to give the item back'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $result = $this->api($request->get('release') ? 'DELETE' : 'POST', 'items/'.rawurlencode((string) $request->get('key')).'/claim');

        return $result->ok() ? $this->result($this->detail($result->data())) : $this->failure($result);
    }
}
