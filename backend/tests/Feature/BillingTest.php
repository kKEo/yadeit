<?php

use App\Enums\WorkspaceRole;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Billing;
use Laravel\Cashier\Events\WebhookHandled;

function billingUrl($workspace, string $suffix = ''): string
{
    return "/api/v1/workspaces/{$workspace->slug}/billing{$suffix}";
}

function subscribe($workspace, string $price = 'price_team', string $status = 'active'): PlanSubscription
{
    $workspace->forceFill(['stripe_id' => 'cus_test_'.$workspace->id])->save();

    return $workspace->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_test_'.$workspace->id, 'stripe_status' => $status, 'stripe_price' => $price, 'quantity' => 1,
    ]);
}

describe('limits', function () {
    beforeEach(fn () => config(['plans.enforce' => true, 'plans.plans.free.members' => 3, 'plans.plans.free.projects' => 2]));

    it('stops inviting when the plan is full, counting open invitations but not agents', function () {
        [$workspace, $owner] = workspaceWith();
        addMember($workspace);
        User::factory()->agent($workspace, $owner)->create();
        User::factory()->agent($workspace, $owner)->create();
        $invite = fn (string $email) => $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->slug}/invitations", ['email' => $email]);

        $invite('third@example.com')->assertCreated();
        $invite('fourth@example.com')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'plan_limit_reached')
            ->assertJsonPath('error.details', ['limit' => 3, 'used' => 3, 'what' => 'members', 'plan' => 'free']);

        $this->getJson(billingUrl($workspace))->assertOk()
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.limits_enforced', true)
            ->assertJsonPath('data.billing_enabled', false)
            ->assertJsonPath('data.limits.members', 3)
            ->assertJsonPath('data.usage', ['members' => 3, 'projects' => 0])
            ->assertJsonPath('data.plans.1.key', 'team')
            ->assertJsonPath('data.plans.1.purchasable', false);

        // A bigger plan makes room.
        $workspace->forceFill(['plan' => 'team'])->save();
        $invite('fourth@example.com')->assertCreated();
    });

    it('stops creating and restoring projects when the plan is full', function () {
        [$workspace, $owner] = workspaceWith();
        $projects = "/api/v1/workspaces/{$workspace->slug}/projects";

        $this->actingAs($owner)->postJson($projects, ['name' => 'One', 'key' => 'ONE'])->assertCreated();
        $this->postJson($projects, ['name' => 'Two', 'key' => 'TWO'])->assertCreated();
        $this->postJson($projects, ['name' => 'Three', 'key' => 'THR'])->assertStatus(402)->assertJsonPath('error.details.what', 'projects');

        // An archived project frees its place, and coming back needs one.
        $this->patchJson("{$projects}/ONE", ['archived' => true])->assertOk();
        $this->postJson($projects, ['name' => 'Three', 'key' => 'THR'])->assertCreated();
        $this->patchJson("{$projects}/ONE", ['archived' => false])->assertStatus(402);
        $this->patchJson("{$projects}/ONE", ['name' => 'Renamed'])->assertOk();
    });

    it('caps the AI allowance by the plan unless the workspace brings its own key', function () {
        [$workspace, $owner] = workspaceWith();
        $ai = "/api/v1/workspaces/{$workspace->slug}/ai";

        // The plan's cap is the limit in force, but it is not the workspace's own setting.
        $this->actingAs($owner)->getJson($ai)->assertJsonPath('data.monthly_token_limit', 200_000)->assertJsonPath('data.own_token_limit', null);

        $workspace->forceFill(['ai_monthly_token_limit' => 50_000])->save();
        $this->getJson($ai)->assertJsonPath('data.monthly_token_limit', 50_000);

        $workspace->forceFill(['ai_monthly_token_limit' => null, 'ai_api_key' => 'sk-ant-own-key-0123456789'])->save();
        $this->getJson($ai)->assertJsonPath('data.monthly_token_limit', (int) config('ai.monthly_token_limit'));
    });
});

it('holds nothing back when limits are switched off', function () {
    config(['plans.plans.free.projects' => 1]);
    [$workspace, $owner] = workspaceWith();
    $projects = "/api/v1/workspaces/{$workspace->slug}/projects";

    $this->actingAs($owner)->postJson($projects, ['name' => 'One', 'key' => 'ONE'])->assertCreated();
    $this->postJson($projects, ['name' => 'Two', 'key' => 'TWO'])->assertCreated();
    $this->getJson(billingUrl($workspace))->assertJsonPath('data.limits_enforced', false);
    $this->getJson("/api/v1/workspaces/{$workspace->slug}/ai")->assertJsonPath('data.monthly_token_limit', (int) config('ai.monthly_token_limit'));
});

it('keeps billing to owners and people, and says when plans cannot be bought', function () {
    [$workspace, $owner] = workspaceWith();
    $admin = addMember($workspace, WorkspaceRole::Admin);
    $member = addMember($workspace);

    $this->actingAs($member)->getJson(billingUrl($workspace))->assertForbidden();
    $this->actingAs($admin)->getJson(billingUrl($workspace))->assertOk();
    $this->postJson(billingUrl($workspace, '/checkout'), ['plan' => 'team'])->assertForbidden();
    $this->postJson(billingUrl($workspace, '/portal'))->assertForbidden();

    // No Stripe key on this installation.
    $this->actingAs($owner)->postJson(billingUrl($workspace, '/checkout'), ['plan' => 'team'])
        ->assertStatus(409)->assertJsonPath('error.code', 'billing_unavailable');
    $this->postJson(billingUrl($workspace, '/checkout'), ['plan' => 'gold'])->assertUnprocessable();
    $this->postJson(billingUrl($workspace, '/portal'))->assertStatus(409)->assertJsonPath('error.code', 'billing_unavailable');

    config(['cashier.secret' => 'sk_test_placeholder', 'plans.plans.team.stripe_price' => 'price_team']);
    // The free plan has no price, and a workspace without a customer has no portal.
    $this->postJson(billingUrl($workspace, '/checkout'), ['plan' => 'free'])->assertStatus(409)->assertJsonPath('error.code', 'billing_unavailable');
    $this->postJson(billingUrl($workspace, '/portal'))->assertStatus(409);
    $this->getJson(billingUrl($workspace))->assertJsonPath('data.billing_enabled', true)->assertJsonPath('data.plans.1.purchasable', true);

    $agent = User::factory()->agent($workspace, $owner)->create();
    $token = $agent->createToken('ci', ['read', 'write'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin')->withToken($token)->getJson(billingUrl($workspace))
        ->assertForbidden()->assertJsonPath('error.code', 'not_available_to_agents');
});

it('follows the Stripe subscription: paid while it is valid, free again when it ends', function () {
    config(['cashier.secret' => 'sk_test_placeholder', 'plans.plans.team.stripe_price' => 'price_team']);
    [$workspace, $owner] = workspaceWith();
    [$other] = workspaceWith();
    $subscription = subscribe($workspace);
    $event = fn (string $type) => event(new WebhookHandled(['type' => $type, 'data' => ['object' => ['customer' => $workspace->stripe_id]]]));

    $event('invoice.paid');
    expect($workspace->refresh()->plan)->toBe('free');

    $event('customer.subscription.created');
    expect($workspace->refresh()->plan)->toBe('team')->and($other->refresh()->plan)->toBe('free');

    $this->actingAs($owner)->getJson(billingUrl($workspace))
        ->assertJsonPath('data.plan', 'team')->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.has_billing_account', true);
    // Already paying: a second checkout is refused, and so is deleting the workspace under a running plan.
    $this->postJson(billingUrl($workspace, '/checkout'), ['plan' => 'team'])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
    $this->deleteJson("/api/v1/workspaces/{$workspace->slug}")->assertStatus(409)->assertJsonPath('error.code', 'active_subscription');

    // A price we do not sell buys nothing.
    $subscription->forceFill(['stripe_price' => 'price_unknown'])->save();
    $event('customer.subscription.updated');
    expect($workspace->refresh()->plan)->toBe('free');

    $subscription->forceFill(['stripe_price' => 'price_team', 'stripe_status' => 'canceled', 'ends_at' => now()->subMinute()])->save();
    $event('customer.subscription.deleted');
    expect($workspace->refresh()->plan)->toBe('free');
    $this->deleteJson("/api/v1/workspaces/{$workspace->slug}")->assertNoContent();

    expect(app(Billing::class))->toBeInstanceOf(Billing::class);
});

it('keeps the Stripe webhook closed until a signing secret is set', function () {
    $this->withoutHeader('Origin')->postJson('/stripe/webhook', ['type' => 'ping', 'data' => ['object' => []]])->assertForbidden();
});

it('accepts a Stripe webhook only with a valid signature, and without a session', function () {
    config(['cashier.webhook.secret' => 'whsec_test']);
    $payload = json_encode(['type' => 'ping', 'data' => ['object' => []]]);
    $post = fn (string $signature) => $this->withoutHeader('Origin')->call('POST', '/stripe/webhook', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature,
    ], content: $payload);
    $time = time();

    $post('t='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_other'))->assertForbidden();
    $post('t='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_test'))->assertOk();
});
