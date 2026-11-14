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

#[Name('search_items')]
#[Description('Finds work items. Returns compact rows; use get_item for the full text of one. Closed items are left out unless open is false.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchItems extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Text in the title, or an exact key'),
            'project' => $schema->string()->description('Project key'),
            'state' => $schema->string()->description('State name, e.g. "In progress"'),
            'state_group' => $schema->string()->enum(['triage', 'backlog', 'unstarted', 'started', 'completed', 'cancelled']),
            'priority' => $schema->string()->enum(['none', 'low', 'medium', 'high', 'urgent']),
            'kind' => $schema->string()->enum(['task', 'bug', 'feature', 'design', 'research']),
            'assignee' => $schema->string()->description('Member id, or "none"'),
            'agent' => $schema->string()->description('"me" for items you hold, an agent id, or "none"'),
            'label' => $schema->string(),
            'needs_input' => $schema->boolean()->description('true: waiting for a person\'s answer'),
            'open' => $schema->boolean()->description('Default true'),
            'limit' => $schema->integer()->description('1-50, default 20'),
            'cursor' => $schema->string()->description('next_cursor of the previous page'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $query = $this->only($request, ['q', 'project', 'state', 'state_group', 'priority', 'kind', 'assignee', 'label', 'cursor']);
        $query['open'] = $request->get('open', true) ? 1 : 0;
        $query['limit'] = max(1, min(50, (int) $request->get('limit', 20)));
        $query['sort'] = '-priority';

        if ($request->get('agent') !== null) {
            $query['delegate'] = $request->get('agent');
        }

        if ($request->get('needs_input') !== null) {
            $query['needs_input'] = $request->get('needs_input') ? 1 : 0;
        }

        $result = $this->api('GET', 'items', $query);

        if (! $result->ok()) {
            return $this->failure($result);
        }

        return $this->result(array_filter([
            'items' => array_map($this->row(...), $result->data()),
            'next_cursor' => $result->body['next_cursor'] ?? null,
        ], fn (mixed $v) => $v !== null));
    }
}
