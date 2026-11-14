<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_item')]
#[Description('Changes fields of an item. Send only what changes. To take, question or hand over work use claim_item, add_comment and submit_for_review instead.')]
#[IsIdempotent]
class UpdateItem extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'title' => $schema->string(),
            'description' => $schema->string()->description('Markdown; replaces the whole description'),
            'state' => $schema->string()->description('State name of the item\'s project (see list_projects)'),
            'priority' => $schema->string()->enum(['none', 'low', 'medium', 'high', 'urgent']),
            'kind' => $schema->string()->enum(['task', 'bug', 'feature', 'design', 'research']),
            'labels' => $schema->array()->items($schema->string())->description('Label names; replaces the current labels'),
            'parent' => $schema->string()->description('Key of the parent item, or "" for none'),
            'due_date' => $schema->string()->description('YYYY-MM-DD, or "" for none'),
            'expected_version' => $schema->integer()->description('The version you last read; the update is refused if the item changed since'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $changes = $this->only($request, ['title', 'description', 'state', 'priority', 'kind', 'labels', 'parent', 'due_date', 'expected_version']);

        foreach (['parent', 'due_date'] as $clearable) {
            if (($changes[$clearable] ?? null) === '') {
                $changes[$clearable] = null;
            }
        }

        if (array_diff_key($changes, ['expected_version' => true]) === []) {
            return Response::error('Nothing to change: pass at least one of title, description, state, priority, kind, labels, parent, due_date.');
        }

        $result = $this->api('PATCH', 'items/'.rawurlencode((string) $request->get('key')), $changes);

        return $result->ok() ? $this->result($this->detail($result->data())) : $this->failure($result);
    }
}
