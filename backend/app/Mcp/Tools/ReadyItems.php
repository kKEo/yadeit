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

#[Name('ready_items')]
#[Description('Work you can start now, most urgent first: not started, not blocked, and either free or already yours. Take one with claim_item.')]
#[IsReadOnly]
#[IsIdempotent]
class ReadyItems extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project key'),
            'limit' => $schema->integer()->description('1-50, default 10'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $result = $this->api('GET', 'items/ready', $this->only($request, ['project', 'limit']));

        return $result->ok()
            ? $this->result(['items' => array_map($this->row(...), $result->data())])
            : $this->failure($result);
    }
}
