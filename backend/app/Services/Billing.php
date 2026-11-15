<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Workspace;
use App\Support\Plans;
use Stripe\Exception\ApiErrorException;

/** Paid plans through Stripe. The workspace is the customer; one price per workspace, whatever its size. Simulated workspaces never reach Stripe. */
class Billing
{
    public const SUBSCRIPTION = 'default';

    /** The address of Stripe's checkout page for moving the workspace to a paid plan. */
    public function checkoutUrl(Workspace $workspace, string $plan): string
    {
        $price = config("plans.plans.{$plan}.stripe_price");

        if (! Plans::billingEnabled() || $workspace->sim_run_id !== null || ! is_string($price) || $price === '') {
            throw new ApiException(409, 'billing_unavailable', 'This plan cannot be bought here. Ask whoever runs this installation.');
        }

        if ($workspace->subscribed(self::SUBSCRIPTION)) {
            throw new ApiException(409, 'conflict', 'This workspace already has a paid plan. Change or cancel it under "Manage billing".');
        }

        $back = $this->settingsUrl($workspace);

        return $this->stripe(fn () => (string) $workspace->newSubscription(self::SUBSCRIPTION, $price)->checkout([
            'success_url' => $back.'?billing=success',
            'cancel_url' => $back.'?billing=cancelled',
        ])->asStripeCheckoutSession()->url);
    }

    /** The address of Stripe's customer portal: invoices, payment method, cancelling. */
    public function portalUrl(Workspace $workspace): string
    {
        if (! Plans::billingEnabled() || ! $workspace->hasStripeId() || $workspace->sim_run_id !== null) {
            throw new ApiException(409, 'billing_unavailable', 'This workspace has no billing account yet.');
        }

        return $this->stripe(fn () => $workspace->billingPortalUrl($this->settingsUrl($workspace)));
    }

    /** Brings the workspace's plan in line with its subscription, after Stripe told us something changed. */
    public function sync(Workspace $workspace): void
    {
        $subscription = $workspace->planSubscription();
        $plan = $subscription !== null && $subscription->valid() ? Plans::keyForPrice($subscription->stripe_price) : null;

        $workspace->forceFill(['plan' => $plan ?? (string) config('plans.default')])->save();
    }

    private function settingsUrl(Workspace $workspace): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/w/{$workspace->slug}/settings";
    }

    /**
     * @param  callable(): string  $call
     */
    private function stripe(callable $call): string
    {
        try {
            return $call();
        } catch (ApiErrorException $e) {
            report($e);

            throw new ApiException(502, 'billing_failed', 'The payment service did not accept the request. Try again in a moment.');
        }
    }
}
