<?php

namespace App\Listeners;

use App\Models\Workspace;
use App\Services\Billing;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/** After Cashier has stored what Stripe reported about a subscription, the workspace's plan follows it. */
class SyncPlanFromStripe
{
    public function __construct(private Billing $billing) {}

    public function handle(WebhookHandled $event): void
    {
        if (! str_starts_with((string) ($event->payload['type'] ?? ''), 'customer.subscription.')) {
            return;
        }

        $customer = $event->payload['data']['object']['customer'] ?? null;
        $workspace = is_string($customer) ? Cashier::findBillable($customer) : null;

        if ($workspace instanceof Workspace) {
            $this->billing->sync($workspace);
        }
    }
}
