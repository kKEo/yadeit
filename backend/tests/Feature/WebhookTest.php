<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->workspace, $this->admin] = workspaceWith(WorkspaceRole::Admin);
    $this->base = "/api/v1/workspaces/{$this->workspace->slug}";
    $this->actingAs($this->admin);
    $this->postJson("{$this->base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();

    // 203.0.113.0/24 is reserved for documentation: public, and never a real host.
    $this->url = 'https://203.0.113.10/hook';
    Http::preventStrayRequests();
});

function hook(array $data = []): array
{
    return test()->postJson(test()->base.'/webhooks', ['name' => 'CI', 'url' => test()->url, ...$data])->assertCreated()->json('data');
}

function deliveries(): mixed
{
    return WebhookDelivery::withoutGlobalScopes()->orderBy('id')->get();
}

it('shows the signing secret once', function () {
    $created = hook();

    expect($created['secret'])->toStartWith('whsec_')->and($created['events'])->toBeNull();

    $this->getJson("{$this->base}/webhooks")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.secret')
        ->assertJsonPath('events.0', 'item.created');

    // Stored encrypted, never in the clear.
    expect(DB::table('webhooks')->value('secret'))->not->toContain($created['secret']);
});

it('sends a signed event when an item changes', function () {
    Http::fake(['*' => Http::response('ok')]);
    $secret = hook()['secret'];

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix login', 'priority' => 'high'])->assertCreated();

    Http::assertSent(function (OutgoingRequest $request) use ($secret) {
        [$t, $v1] = sscanf($request->header('X-Yadeit-Signature')[0], 't=%d,v1=%s');
        $payload = $request->data();

        return $request->url() === $this->url
            && $request->header('X-Yadeit-Event')[0] === 'item.created'
            && $request->header('X-Yadeit-Delivery')[0] === $payload['id']
            && hash_equals(hash_hmac('sha256', $t.'.'.$request->body(), $secret), $v1)
            && abs(time() - $t) < 60
            && $payload['item']['key'] === 'WEB-1'
            && $payload['item']['priority'] === 'high'
            && $payload['item']['url'] === "http://localhost:5173/w/{$this->workspace->slug}/items/WEB-1"
            && $payload['actor'] === ['id' => $this->admin->id, 'name' => $this->admin->name, 'type' => 'human']
            && $payload['workspace'] === $this->workspace->slug;
    });

    expect(deliveries())->toHaveCount(1)
        ->and(deliveries()[0]->succeeded)->toBeTrue()
        ->and(deliveries()[0]->status_code)->toBe(200);
});

it('sends only the events a webhook asked for, with comment text and agent details', function () {
    Http::fake(['*' => Http::response('ok')]);
    hook(['events' => ['item.input_requested', 'item.commented']]);

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix login'])->assertCreated();
    Http::assertNothingSent();

    $agent = User::factory()->agent($this->workspace, $this->admin)->create(['name' => 'Coder']);
    $token = $agent->createToken('t', ['read', 'write'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin')->withToken($token)->postJson("{$this->base}/items/WEB-1/ask", ['question' => 'Which provider?'])->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn (OutgoingRequest $request) => $request->data()['event'] === 'item.input_requested'
        && $request->data()['actor']['type'] === 'agent'
        && $request->data()['comment']['body'] === 'Which provider?'
        && $request->data()['item']['needs_input'] === true);
});

it('retries a failing receiver and logs every attempt under one event id', function () {
    Http::fakeSequence()->push('down', 503)->push('down', 503)->push('ok', 200);
    hook();

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix login'])->assertCreated();

    $log = deliveries();
    expect($log->pluck('attempt')->all())->toBe([1, 2, 3])
        ->and($log->pluck('succeeded')->all())->toBe([false, false, true])
        ->and($log->pluck('event_id')->unique())->toHaveCount(1)
        ->and($log[0]->error)->toBe('The receiver answered 503.')
        ->and(Webhook::withoutGlobalScopes()->first()->consecutive_failures)->toBe(0);
});

it('does not repeat a request the receiver calls wrong', function () {
    Http::fake(['*' => Http::response('nope', 400)]);
    hook();

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix login'])->assertCreated();

    expect(deliveries())->toHaveCount(1)
        ->and(Webhook::withoutGlobalScopes()->first()->consecutive_failures)->toBe(1);
});

it('switches itself off after repeated failures and can be switched on again', function () {
    config(['webhooks.disable_after_failures' => 2, 'webhooks.retry_after' => []]);
    Http::fake(['*' => Http::response('down', 500)]);
    $id = hook()['id'];

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'One'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Two'])->assertCreated();
    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Three'])->assertCreated();

    // The third event was never attempted.
    expect(deliveries())->toHaveCount(2);
    $this->getJson("{$this->base}/webhooks")->assertJsonPath('data.0.is_active', false)->assertJsonPath('data.0.disabled_at', fn ($at) => $at !== null);
    $this->postJson("{$this->base}/webhooks/{$id}/ping")->assertStatus(409);

    $this->patchJson("{$this->base}/webhooks/{$id}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.disabled_at', null);
});

it('refuses addresses on private networks or without https', function (string $url, string $reason) {
    $this->postJson("{$this->base}/webhooks", ['name' => 'Bad', 'url' => $url])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.url.0', $reason);
})->with([
    'loopback' => ['https://127.0.0.1/hook', 'The address points to a private or local network, which is not allowed.'],
    'private range' => ['https://10.0.0.5/hook', 'The address points to a private or local network, which is not allowed.'],
    'cloud metadata' => ['https://169.254.169.254/latest', 'The address points to a private or local network, which is not allowed.'],
    'ipv6 loopback' => ['https://[::1]/hook', 'The address points to a private or local network, which is not allowed.'],
    'plain http' => ['http://203.0.113.10/hook', 'Use an https address.'],
    'credentials' => ['https://user:pw@203.0.113.10/hook', 'Do not put a user name or password in the address.'],
    'other scheme' => ['file:///etc/passwd', 'Use an http or https address.'],
]);

it('checks the address again at delivery time', function () {
    Http::fake(['*' => Http::response('ok')]);
    hook();
    DB::table('webhooks')->update(['url' => 'https://10.0.0.5/hook']);

    $this->postJson("{$this->base}/items", ['project' => 'WEB', 'title' => 'Fix login'])->assertCreated();

    Http::assertNothingSent();
    expect(deliveries()[0]->error)->toBe('The address points to a private or local network, which is not allowed.');
});

it('sends a ping and shows the delivery log newest first', function () {
    Http::fake(['*' => Http::response('ok')]);
    $id = hook()['id'];

    $eventId = $this->postJson("{$this->base}/webhooks/{$id}/ping")->assertStatus(202)->json('data.event_id');

    $this->getJson("{$this->base}/webhooks/{$id}/deliveries")
        ->assertOk()
        ->assertJsonPath('data.0.event', 'ping')
        ->assertJsonPath('data.0.event_id', $eventId)
        ->assertJsonPath('data.0.succeeded', true)
        ->assertJsonMissingPath('data.0.payload');
});

it('is managed by admins only, never by members or agents', function () {
    $id = hook()['id'];
    $member = addMember($this->workspace);
    $agent = User::factory()->agent($this->workspace, $this->admin)->create();
    $this->workspace->memberships()->where('user_id', $agent->id)->update(['role' => 'admin']);

    $this->actingAs($member);
    $this->getJson("{$this->base}/webhooks")->assertForbidden();
    $this->postJson("{$this->base}/webhooks", ['name' => 'x', 'url' => $this->url])->assertForbidden();
    $this->deleteJson("{$this->base}/webhooks/{$id}")->assertForbidden();

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin')->withToken($agent->createToken('t', ['read', 'write'])->plainTextToken)
        ->getJson("{$this->base}/webhooks")->assertForbidden()->assertJsonPath('error.code', 'not_available_to_agents');
});

it('keeps webhooks of one workspace away from another', function () {
    Http::fake(['*' => Http::response('ok')]);
    $id = hook()['id'];
    [$other, $stranger] = workspaceWith(WorkspaceRole::Admin);
    $otherBase = "/api/v1/workspaces/{$other->slug}";

    $this->actingAs($stranger);
    $this->getJson("{$otherBase}/webhooks")->assertJsonCount(0, 'data');
    $this->deleteJson("{$otherBase}/webhooks/{$id}")->assertNotFound();

    // A change in the other workspace does not reach this webhook.
    $this->postJson("{$otherBase}/projects", ['name' => 'Other', 'key' => 'OTH'])->assertCreated();
    $this->postJson("{$otherBase}/items", ['project' => 'OTH', 'title' => 'Private'])->assertCreated();
    Http::assertNothingSent();
});
