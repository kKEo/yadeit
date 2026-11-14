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

#[Name('list_projects')]
#[Description('Lists projects. Pass a project key to get its states and labels, which are the values update_item and search_items accept.')]
#[IsReadOnly]
#[IsIdempotent]
class ListProjects extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['project' => $schema->string()->description('Project key, e.g. WEB. Omit to list all projects.')];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $key = $request->get('project');
        $result = $this->api('GET', $key ? 'projects/'.rawurlencode((string) $key) : 'projects');

        if (! $result->ok()) {
            return $this->failure($result);
        }

        if (! $key) {
            return $this->result(['projects' => array_map(
                fn (array $p) => array_filter(['key' => $p['key'], 'name' => $p['name'], 'description' => $p['description'], 'archived' => $p['archived'] ?: null]),
                $result->data(),
            )]);
        }

        $project = $result->data();

        return $this->result([
            'key' => $project['key'],
            'name' => $project['name'],
            'description' => $project['description'],
            'states' => array_map(fn (array $s) => $s['name'].' ('.$s['group'].')', $project['states'] ?? []),
            'labels' => array_column($project['labels'] ?? [], 'name'),
        ]);
    }
}
