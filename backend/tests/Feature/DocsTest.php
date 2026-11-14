<?php

use App\Mcp\YadeitServer;
use App\Models\Webhook;

it('serves an llms.txt index that links to every page', function () {
    $response = $this->get('/llms.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/markdown; charset=UTF-8');

    $text = $response->getContent();
    expect($text)->toStartWith("# Yadeit\n\n> Yadeit is a project tracker");

    preg_match_all('#\]\(('.preg_quote(url('/'), '#').'[^)]+)\)#', $text, $links);
    expect($links[1])->toHaveCount(5);

    foreach ($links[1] as $link) {
        $path = parse_url($link, PHP_URL_PATH);

        // The OpenAPI file is served by the web server straight from public/.
        $path === '/openapi.json'
            ? expect(public_path('openapi.json'))->toBeFile()
            : $this->get($path)->assertOk();
    }
});

it('serves the guides as Markdown with absolute addresses', function () {
    $api = $this->get('/docs/api.md')->assertOk()->getContent();

    expect($api)->toStartWith('# Yadeit API guide')
        ->toContain('Base address: `'.url('/api/v1').'`')
        ->toContain(']('.url('/docs/reference.md').')')
        ->not->toContain('{{api}}');

    expect($this->get('/docs/webhooks.md')->assertOk()->getContent())->toContain('X-Yadeit-Signature')->toContain('hmac.compare_digest');

    expect($this->get('/docs/agents.md')->assertOk()->getContent())
        ->toStartWith('# Working in Yadeit as an agent')
        ->toContain('claude mcp add --transport http yadeit '.url('/mcp'))
        ->not->toContain('        1. `whoami`');
});

it('lists every documented endpoint in the reference', function () {
    $reference = $this->get('/docs/reference.md')->assertOk()->getContent();
    $document = json_decode(file_get_contents(public_path('openapi.json')), true);

    foreach ($document['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            expect($reference)->toContain('### '.strtoupper($method).' '.$path);
        }
    }

    expect($reference)
        ->toContain("### POST /workspaces/{workspace}/items/{item}/claim\n\nTakes the item to work on.")
        ->toContain('Body: `project*`, `title*`')
        ->toContain('Query: `project`, `limit`');
});

it('names only tools and events that exist', function () {
    $tools = collect((new ReflectionClass(YadeitServer::class))->getDefaultProperties()['tools'])
        ->map(fn (string $class) => (new $class)->name());

    $agents = $this->get('/docs/agents.md')->getContent();
    preg_match('/The tools are: (.+?)\. This guide/', $agents, $match);
    preg_match_all('/`([a-z_]+)`/', $match[1], $listed);

    expect($listed[1])->toEqualCanonicalizing($tools->all());

    $webhooks = $this->get('/docs/webhooks.md')->getContent();

    foreach (Webhook::EVENTS as $event) {
        expect($webhooks)->toContain("`{$event}`");
    }
});

it('answers unknown pages with 404', function () {
    $this->get('/docs/secrets.md')->assertNotFound();
    $this->get('/docs/../.env.md')->assertNotFound();
});
