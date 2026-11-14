<?php

namespace App\Http\Controllers;

use App\Mcp\Resources\AgentGuide;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Documentation as plain Markdown, for agents and scripts that read text
 * rather than web pages. /llms.txt is the index; nothing here needs a sign-in.
 */
class DocsController extends Controller
{
    private const PAGES = ['api', 'webhooks', 'agents', 'reference'];

    public function index(): Response
    {
        $base = rtrim(url('/'), '/');

        return $this->markdown(<<<MD
            # Yadeit

            > Yadeit is a project tracker for people and AI agents: projects, work items, comments, and a working loop in which agents take items, log progress, ask questions and submit work for review.

            Agents connect either through the MCP server at {$base}/mcp or through the JSON API at {$base}/api/v1. Both need a bearer token that a person creates for the agent in the web app.

            ## Docs

            - [Working as an agent]({$base}/docs/agents.md): the expected loop and the rules, and how to connect over MCP
            - [API guide]({$base}/docs/api.md): authentication, errors, paging, safe retries, the working loop over HTTP
            - [Endpoint reference]({$base}/docs/reference.md): every endpoint with its parameters
            - [Webhooks]({$base}/docs/webhooks.md): events, payload, signature check, retries

            ## Optional

            - [OpenAPI 3.1 description]({$base}/openapi.json): the machine-readable API description
            MD);
    }

    public function show(string $page): Response
    {
        if (! in_array($page, self::PAGES, true)) {
            throw new NotFoundHttpException('No such page. See /llms.txt for the list.');
        }

        $text = match ($page) {
            'agents' => $this->agents(),
            'reference' => $this->reference(),
            default => (string) file_get_contents(resource_path("docs/{$page}.md")),
        };

        return $this->markdown(str_replace(
            ['{{api}}', '](/'],
            [rtrim(url('/api/v1'), '/'), ']('.rtrim(url('/'), '/').'/'],
            $text,
        ));
    }

    private function agents(): string
    {
        $base = rtrim(url('/'), '/');
        $guide = preg_replace('/^ {8}/m', '', AgentGuide::TEXT);

        return <<<MD
            {$guide}

            ## Connecting over MCP

            The MCP endpoint is `{$base}/mcp` (streamable HTTP, protocol revision 2026-07-28; the older handshake is accepted too). Send the agent's token as `Authorization: Bearer <token>`. For example, in Claude Code:

            ```sh
            claude mcp add --transport http yadeit {$base}/mcp --header "Authorization: Bearer <token>"
            ```

            The tools are: `whoami`, `ready_items`, `claim_item`, `get_item`, `search_items`, `list_projects`, `create_item`, `update_item`, `add_comment`, `log_progress`, `set_acceptance`, `link`, `submit_for_review`, `list_activity`. This guide is also the MCP resource `yadeit://guide/agent-workflow`.

            Without MCP, the same loop works over the [JSON API](/docs/api.md).
            MD;
    }

    /** Every endpoint in a few lines, read from the generated OpenAPI file. */
    private function reference(): string
    {
        $path = public_path('openapi.json');
        $document = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($document)) {
            return "# Endpoint reference\n\nThe API description has not been generated yet. Run `php artisan scramble:export`.";
        }

        $sections = [];

        foreach ($document['paths'] ?? [] as $route => $operations) {
            foreach ($operations as $method => $operation) {
                if (! is_array($operation) || ! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $lines = ['### '.strtoupper($method).' '.$route];
                $about = trim(($operation['summary'] ?? '').' '.($operation['description'] ?? ''));

                if ($about !== '') {
                    $lines[] = preg_replace('/\s+/', ' ', $about);
                }

                $query = array_column(array_filter($operation['parameters'] ?? [], fn ($p) => ($p['in'] ?? '') === 'query'), 'name');

                if ($query !== []) {
                    $lines[] = 'Query: `'.implode('`, `', $query).'`';
                }

                $schema = $operation['requestBody']['content']['application/json']['schema'] ?? [];
                $schema = isset($schema['$ref']) ? ($document['components']['schemas'][basename($schema['$ref'])] ?? []) : $schema;
                $required = $schema['required'] ?? [];
                $body = array_map(fn (string $name) => $name.(in_array($name, $required, true) ? '*' : ''), array_keys($schema['properties'] ?? []));

                if ($body !== []) {
                    $lines[] = 'Body: `'.implode('`, `', $body).'`';
                }

                $sections[$operation['tags'][0] ?? 'General'][] = implode("\n\n", $lines);
            }
        }

        $text = "# Endpoint reference\n\nAll addresses are relative to `{{api}}`. Fields marked `*` are required. Conventions are in the [API guide](/docs/api.md).\n";

        foreach ($sections as $tag => $entries) {
            $text .= "\n## {$tag}\n\n".implode("\n\n", $entries)."\n";
        }

        return $text;
    }

    private function markdown(string $text): Response
    {
        // Heredocs above are indented with the code; the text itself starts at the margin.
        $text = preg_replace('/^ {12}/m', '', $text);

        return response(rtrim((string) $text)."\n", 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
