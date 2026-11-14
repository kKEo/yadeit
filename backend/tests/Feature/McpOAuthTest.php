<?php

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Testing\TestResponse;

// The whole road of a remote MCP client: find out how to sign in, register,
// send its user to the consent screen, trade the code for a token, call tools.

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);

    [$this->workspace, $this->person] = workspaceWith(WorkspaceRole::Member);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->person)->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix login'])->assertCreated();
    $this->agent = User::factory()->agent($this->workspace, $this->person)->create(['name' => 'Coder']);

    $this->redirect = 'https://client.example/callback';
    $this->verifier = str_repeat('v', 64);
    $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
});

function signedOut(): mixed
{
    app('auth')->forgetGuards();
    test()->flushSession();

    return test()->withoutToken()->withoutHeader('Origin');
}

function registerClient(): string
{
    return signedOut()->postJson('/oauth/register', ['client_name' => 'Remote Assistant', 'redirect_uris' => [test()->redirect]])
        ->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none')->json('client_id');
}

function authorizeUrl(string $clientId): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $clientId, 'redirect_uri' => test()->redirect, 'response_type' => 'code', 'scope' => 'mcp:use',
        'state' => 'xyz', 'code_challenge' => test()->challenge, 'code_challenge_method' => 'S256',
    ]);
}

/** Walks the consent screen as the person and returns the access and refresh tokens. */
function connect(string $clientId, User $person, int $agentId): array
{
    $page = test()->actingAs($person, 'web')->get(authorizeUrl($clientId))->assertOk();
    preg_match('/name="auth_token" value="([^"]+)"/', $page->getContent(), $m);

    $location = test()->post('/oauth/connect', ['agent' => $agentId, 'auth_token' => $m[1], 'client_id' => $clientId, 'state' => 'xyz'])
        ->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($location)->toStartWith(test()->redirect)->and($query['state'])->toBe('xyz');

    return signedOut()->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => test()->redirect,
        'code' => $query['code'], 'code_verifier' => test()->verifier,
    ])->assertOk()->json();
}

function oauthMcp(string $token, string $method, array $params = []): TestResponse
{
    $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []];

    return signedOut()->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], array_filter([
        'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method, 'Mcp-Name' => $params['name'] ?? null,
    ]));
}

function toolText(TestResponse $response): string
{
    return (string) $response->assertOk()->json('result.content.0.text');
}

it('tells a client where to sign in', function () {
    $challenge = signedOut()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
        ->assertUnauthorized()->headers->get('WWW-Authenticate');
    expect($challenge)->toContain('oauth-protected-resource');

    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use']);
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
        ->assertJsonPath('registration_endpoint', url('/oauth/register'));
});

it('sends a signed-out person to the web app to sign in and back', function () {
    $clientId = registerClient();

    $location = signedOut()->get(authorizeUrl($clientId))->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith(rtrim(config('app.frontend_url'), '/').'/login?redirect=')
        ->and(urldecode($location))->toContain('/oauth/authorize?client_id='.$clientId);
});

it('connects an app as the agent its user chose, with that agent\'s guardrails', function () {
    $clientId = registerClient();

    $this->actingAs($this->person, 'web')->get(authorizeUrl($clientId))->assertOk()
        ->assertSee('Remote Assistant')->assertSee('client.example')->assertSee('Coder')->assertSee($this->workspace->name);

    $tokens = connect($clientId, $this->person, $this->agent->id);
    expect($tokens['token_type'])->toBe('Bearer')->and($tokens)->toHaveKey('refresh_token');

    expect(toolText(oauthMcp($tokens['access_token'], 'tools/call', ['name' => 'whoami', 'arguments' => (object) []])))->toContain('Coder');
    expect(toolText(oauthMcp($tokens['access_token'], 'tools/call', ['name' => 'get_item', 'arguments' => ['key' => 'WEB-1']])))->toContain('Fix login');

    // What the app does is the agent's doing, and an agent does not close items.
    $closing = oauthMcp($tokens['access_token'], 'tools/call', ['name' => 'update_item', 'arguments' => ['key' => 'WEB-1', 'state' => 'Done']])->assertOk();
    expect($closing->json('result.isError'))->toBeTrue()->and(toolText($closing))->toContain('submit');
    oauthMcp($tokens['access_token'], 'tools/call', ['name' => 'add_comment', 'arguments' => ['key' => 'WEB-1', 'body' => 'From the app']])->assertOk();
    asUser($this->person)->getJson("{$this->base}/items/WEB-1/comments")->assertJsonPath('data.0.author.id', $this->agent->id);

    // The token is for the MCP endpoint only.
    signedOut()->withToken($tokens['access_token'])->getJson("{$this->base}/items")->assertUnauthorized();
    signedOut()->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();

    expect(AuditEvent::query()->where('action', 'agent.app_connected')->value('details'))->toBe(['app' => 'Remote Assistant']);
});

it('refuses an agent that is not the person\'s own, and a wrong code verifier', function () {
    $clientId = registerClient();
    $someoneElses = User::factory()->agent($this->workspace, addMember($this->workspace))->create();

    $page = $this->actingAs($this->person, 'web')->get(authorizeUrl($clientId))->assertOk()->assertDontSee($someoneElses->name);
    preg_match('/name="auth_token" value="([^"]+)"/', $page->getContent(), $m);
    $this->post('/oauth/connect', ['agent' => $someoneElses->id, 'auth_token' => $m[1], 'client_id' => $clientId, 'state' => 'xyz'])
        ->assertSessionHasErrors('agent');
    expect(McpConnection::query()->count())->toBe(0);

    $location = $this->post('/oauth/connect', ['agent' => $this->agent->id, 'auth_token' => $m[1], 'client_id' => $clientId, 'state' => 'xyz'])->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    signedOut()->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => $this->redirect,
        'code' => $query['code'], 'code_verifier' => str_repeat('w', 64),
    ])->assertStatus(400);
});

it('ends the access when the app is disconnected or its user leaves the workspace', function () {
    $clientId = registerClient();
    $tokens = connect($clientId, $this->person, $this->agent->id);
    $call = fn () => oauthMcp($tokens['access_token'], 'tools/call', ['name' => 'whoami', 'arguments' => (object) []]);
    $call()->assertOk();

    // The person leaves: the agent has nobody to work for.
    $admin = addMember($this->workspace, WorkspaceRole::Admin);
    asUser($admin)->deleteJson("{$this->base}/members/{$this->person->id}")->assertNoContent();
    $call()->assertForbidden();

    $this->workspace->memberships()->create(['user_id' => $this->person->id, 'role' => WorkspaceRole::Member]);
    $call()->assertOk();

    // Disconnecting on the Agents page revokes the tokens, the refresh token included.
    $id = asUser($admin)->getJson("{$this->base}/agents/{$this->agent->id}/connections")
        ->assertOk()->assertJsonPath('data.0.app', 'Remote Assistant')->json('data.0.id');
    $this->deleteJson("{$this->base}/agents/{$this->agent->id}/connections/{$id}")->assertNoContent();

    $call()->assertUnauthorized();
    signedOut()->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
    expect(AuditEvent::query()->where('action', 'agent.app_disconnected')->exists())->toBeTrue();
});
