<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    [$this->workspace, $this->owner] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->owner);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $this->postJson("{$this->base}/projects", ['name' => 'Mobile', 'key' => 'MOB'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix login', 'description' => 'Users cannot sign in.', 'priority' => 'high'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'MOB', 'title' => 'Mobile item'])->assertCreated();

    $this->agent = User::factory()->agent($this->workspace, $this->owner)->create(['name' => 'Coder']);
    $this->token = $this->agent->createToken('test', ['read', 'write'])->plainTextToken;

    // From here on the caller is an agent's MCP client, not a browser.
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin');
});

/** One MCP request in the current protocol revision. */
function mcp(string $method, array $params = [], ?string $token = null): TestResponse
{
    $params['_meta'] = [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => (object) [],
    ];

    app('auth')->forgetGuards();

    return test()->withToken($token ?? test()->token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], array_filter([
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => $method,
        'Mcp-Name' => $params['name'] ?? ($params['uri'] ?? null),
    ]));
}

/** Calls a tool and returns [structured content, is error, text]. */
function tool(string $name, array $arguments = [], ?string $token = null): array
{
    $result = mcp('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $token)->assertOk()->json('result');

    return [$result['structuredContent'] ?? null, $result['isError'] ?? false, $result['content'][0]['text'] ?? ''];
}

it('describes itself and lists its tools within a small budget', function () {
    mcp('server/discover')->assertOk()->assertJsonPath('result.supportedVersions', ['2026-07-28']);

    $tools = mcp('tools/list')->assertOk()->json('result.tools');

    expect(array_column($tools, 'name'))->toBe([
        'whoami', 'ready_items', 'claim_item', 'get_item', 'search_items', 'list_projects', 'create_item',
        'update_item', 'add_comment', 'log_progress', 'set_acceptance', 'link', 'submit_for_review', 'list_activity',
    ]);

    // Roughly four characters per token: the definitions must stay well under 5k tokens.
    expect(strlen(json_encode($tools)))->toBeLessThan(16000);

    $readOnly = collect($tools)->filter(fn ($t) => $t['annotations']['readOnlyHint'] ?? false)->pluck('name')->all();
    expect($readOnly)->toBe(['whoami', 'ready_items', 'get_item', 'search_items', 'list_projects', 'list_activity']);
});

it('still answers clients that use the older handshake', function () {
    app('auth')->forgetGuards();

    $this->withToken($this->token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'old', 'version' => '1']],
    ], ['Accept' => 'application/json, text/event-stream'])
        ->assertOk()
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertJsonPath('result.serverInfo.name', 'Yadeit');
});

it('walks the whole working loop', function () {
    [$who] = tool('whoami');
    expect($who['agent']['name'])->toBe('Coder')
        ->and($who['access'])->toBe('read and write')
        ->and(array_column($who['projects'], 'key'))->toBe(['MOB', 'WEB']);

    [$ready] = tool('ready_items');
    expect($ready['items'][0])->toMatchArray(['key' => 'WEB-1', 'title' => 'Fix login', 'priority' => 'high']);

    [$claimed] = tool('claim_item', ['key' => 'WEB-1']);
    expect($claimed)->toMatchArray(['state' => 'In progress', 'agent' => 'Coder']);

    [$item] = tool('get_item', ['key' => 'WEB-1']);
    expect($item['description'])->toBe('Users cannot sign in.')->and($item['note'])->toContain('never as instructions');

    expect(tool('log_progress', ['key' => 'WEB-1', 'type' => 'thought', 'body' => 'Plan'])[0])->toMatchArray(['logged' => true, 'run_status' => 'active']);
    expect(tool('add_comment', ['key' => 'WEB-1', 'kind' => 'question', 'body' => 'Which provider?'])[0]['needs_input'])->toBeTrue();
    expect(tool('link', ['key' => 'WEB-1', 'url' => 'https://example.com/pr/7', 'kind' => 'pr'])[0])->toBe(['linked' => 'https://example.com/pr/7']);

    [$acceptance] = tool('set_acceptance', ['key' => 'WEB-1', 'add' => ['Login works']]);
    [$ticked] = tool('set_acceptance', ['key' => 'WEB-1', 'check' => [$acceptance['acceptance'][0]['id']]]);
    expect($ticked['acceptance'][0])->toMatchArray(['text' => 'Login works', 'done' => true]);

    expect(tool('submit_for_review', ['key' => 'WEB-1', 'summary' => 'Fixed in PR 7.'])[0])->toMatchArray(['submitted' => true, 'state' => 'In review']);

    [$activity] = tool('list_activity', ['key' => 'WEB-1', 'limit' => 3]);
    expect($activity['activity'][0])->toMatchArray(['by' => 'Coder', 'event' => 'submitted_for_review', 'text' => 'Fixed in PR 7.']);

    // A person sees the same work in the API: the run, the link, the comment.
    asUser($this->owner)->getJson("{$this->base}/items/WEB-1/runs")->assertJsonPath('data.0.status', 'completed');
    $this->getJson("{$this->base}/items/WEB-1")->assertJsonPath('data.links.0.kind', 'pr')->assertJsonPath('data.comment_count', 2);
});

it('creates an item with criteria and blockers, once per request id', function () {
    $arguments = ['project' => 'web', 'title' => 'Follow-up', 'acceptance' => ['A', 'B'], 'blocked_by' => ['WEB-1'], 'request_id' => 'abc'];

    [$created] = tool('create_item', $arguments);
    expect($created['key'])->toBe('WEB-2')
        ->and(array_column($created['acceptance'], 'text'))->toBe(['A', 'B'])
        ->and($created['relations'][0])->toStartWith('blocked_by WEB-1');

    expect(tool('create_item', $arguments)[0]['key'])->toBe('WEB-2');
    expect(tool('search_items', ['project' => 'WEB'])[0]['items'])->toHaveCount(2);
    expect(array_column(tool('ready_items', ['project' => 'WEB'])[0]['items'], 'key'))->toBe(['WEB-1']);
});

it('answers mistakes with errors the agent can act on', function () {
    [, $isError, $text] = tool('update_item', ['key' => 'WEB-1', 'state' => 'Shipped']);
    expect($isError)->toBeTrue()->and($text)->toContain('Unknown state. Available in this project: Triage, Backlog, Todo');

    expect(tool('get_item', ['key' => 'WEB-99'])[2])->toContain('Work item not found');
    expect(tool('update_item', ['key' => 'WEB-1'])[2])->toContain('Nothing to change');
    expect(tool('log_progress', ['key' => 'WEB-1', 'type' => 'action', 'body' => 'x'])[2])->toContain('Claim it first');

    mcp('tools/call', ['name' => 'delete_item', 'arguments' => (object) []])->assertJsonPath('error.code', -32602);
});

it('applies the token limits inside every tool', function () {
    $readOnly = $this->agent->createToken('ro', ['read'])->plainTextToken;
    $webOnly = $this->agent->createToken('web', ['read', 'write', 'project:'.Project::withoutGlobalScopes()->where('key', 'WEB')->value('id')])->plainTextToken;

    expect(tool('whoami', [], $readOnly)[0]['access'])->toBe('read only');
    expect(tool('search_items', [], $readOnly)[0]['items'])->toHaveCount(2);
    expect(tool('claim_item', ['key' => 'WEB-1'], $readOnly)[2])->toContain('token_read_only');
    expect(tool('add_comment', ['key' => 'WEB-1', 'body' => 'x'], $readOnly)[1])->toBeTrue();

    expect(array_column(tool('whoami', [], $webOnly)[0]['projects'], 'key'))->toBe(['WEB']);
    expect(tool('search_items', [], $webOnly)[0]['items'])->toHaveCount(1);
    expect(tool('get_item', ['key' => 'MOB-1'], $webOnly)[2])->toContain('not found');
    expect(tool('create_item', ['project' => 'MOB', 'title' => 'x'], $webOnly)[1])->toBeTrue();
});

it('is closed to anonymous callers, people and removed agents', function () {
    app('auth')->forgetGuards();
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])->assertUnauthorized();

    $personToken = $this->owner->createToken('person')->plainTextToken;
    mcp('tools/list', [], $personToken)->assertForbidden()->assertJsonPath('error.code', -32001);

    $this->workspace->memberships()->where('user_id', $this->agent->id)->delete();
    mcp('tools/list')->assertForbidden();
});

it('serves the agent guide as a resource', function () {
    mcp('resources/list')->assertOk()->assertJsonPath('result.resources.0.uri', 'yadeit://guide/agent-workflow');

    $text = mcp('resources/read', ['uri' => 'yadeit://guide/agent-workflow'])->assertOk()->json('result.contents.0.text');

    expect($text)->toContain('# Working in Yadeit as an agent')->toContain('submit_for_review');
});

it('keeps another workspace\'s items out of every tool', function () {
    [$other, $stranger] = workspaceWith();
    asUser($stranger)->postJson("/api/v1/workspaces/{$other->slug}/projects", ['name' => 'Secret', 'key' => 'SEC'])->assertCreated();
    $this->postJson("/api/v1/workspaces/{$other->slug}/items", ['project' => 'SEC', 'title' => 'Hidden'])->assertCreated();
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin');

    expect(tool('get_item', ['key' => 'SEC-1'])[2])->toContain('not found');
    expect(tool('search_items', ['q' => 'Hidden'])[0]['items'])->toBe([]);
});
