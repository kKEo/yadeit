<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_item')]
#[Description('Creates a work item, for example a follow-up or a bug you found. Only project and title are required.')]
class CreateItem extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project key')->required(),
            'title' => $schema->string()->description('Short and specific')->required(),
            'description' => $schema->string()->description('Markdown'),
            'priority' => $schema->string()->enum(['none', 'low', 'medium', 'high', 'urgent']),
            'kind' => $schema->string()->enum(['task', 'bug', 'feature', 'design', 'research']),
            'state' => $schema->string()->description('State name; default is the project\'s default state'),
            'labels' => $schema->array()->items($schema->string())->description('Label names'),
            'parent' => $schema->string()->description('Key of the parent item'),
            'blocked_by' => $schema->array()->items($schema->string())->description('Keys of items that must be done first'),
            'acceptance' => $schema->array()->items($schema->string())->description('Acceptance criteria, one per entry'),
            'request_id' => $schema->string()->description('Any unique string; a retry with the same value does not create a duplicate'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $headers = $request->get('request_id') ? ['Idempotency-Key' => 'mcp-'.$request->get('request_id')] : [];
        $created = $this->api('POST', 'items', $this->only($request, ['project', 'title', 'description', 'priority', 'kind', 'state', 'labels', 'parent']), $headers);

        if (! $created->ok()) {
            return $this->failure($created);
        }

        $key = $created->data()['key'];
        $problems = [];

        foreach ((array) $request->get('acceptance', []) as $text) {
            $added = $this->api('POST', "items/{$key}/checklist", ['text' => (string) $text]);
            $added->ok() || $problems[] = "acceptance \"{$text}\": ".$added->error();
        }

        foreach ((array) $request->get('blocked_by', []) as $blocker) {
            $linked = $this->api('POST', 'items/'.rawurlencode((string) $blocker).'/relations', ['type' => 'blocks', 'item' => $key]);
            $linked->ok() || $problems[] = "blocked_by {$blocker}: ".$linked->error();
        }

        $item = $this->api('GET', "items/{$key}");

        return $this->result(array_filter([
            ...$this->detail($item->ok() ? $item->data() : $created->data()),
            'warnings' => $problems ?: null,
        ]));
    }
}
