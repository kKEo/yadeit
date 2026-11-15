<?php

namespace App\Mcp;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Lets MCP tools call the JSON API from inside the application, as the same
 * agent with the same token. Every tool therefore gets exactly the API's
 * validation, permissions, token limits and error messages, with no second
 * implementation to drift.
 */
class ApiBridge
{
    public function __construct(private Router $router) {}

    /**
     * @param  array<string, mixed>  $data  Query for GET, JSON body otherwise.
     * @param  array<string, string>  $headers
     */
    public function call(string $method, string $path, array $data = [], array $headers = []): ApiResult
    {
        $outer = request();

        /** @var Workspace $workspace */
        $workspace = $outer->attributes->get('mcp.workspace');
        $uri = '/api/v1/workspaces/'.$workspace->slug.($path === '' ? '' : '/'.ltrim($path, '/'));
        $isRead = in_array($method, ['GET', 'HEAD'], true);

        $server = ['HTTP_ACCEPT' => 'application/json'];

        // An agent's own token travels with the inner request. An app connected over OAuth has none:
        // its request was already turned into the agent's, and its OAuth token means nothing to the API.
        if (! $outer->attributes->get('mcp.oauth')) {
            $server['HTTP_AUTHORIZATION'] = (string) $outer->header('Authorization');
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $inner = $isRead
            ? Request::create($uri, $method, $data, server: $server)
            : Request::create($uri, $method, server: [...$server, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode((object) $data));

        $inner->setUserResolver($outer->getUserResolver());
        $inner->attributes->set('mcp.oauth', (bool) $outer->attributes->get('mcp.oauth'));
        // A simulated agent stays simulated on the inside, so the same allowance applies to both requests.
        $inner->attributes->set('sim', $outer->attributes->get('sim'));

        // The inner request is "the request" while it runs, then the outer one is put back.
        app()->instance('request', $inner);

        try {
            $response = $this->router->dispatch($inner);
        } finally {
            app()->instance('request', $outer);
        }

        $body = json_decode((string) $response->getContent(), true);

        return new ApiResult($response->getStatusCode(), is_array($body) ? $body : []);
    }
}
