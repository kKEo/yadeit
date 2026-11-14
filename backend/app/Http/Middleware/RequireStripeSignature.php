<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe's webhook changes what a workspace has paid for, so it is only
 * accepted when its signature can be checked. Without a signing secret the
 * endpoint is closed, instead of trusting whoever posts to it.
 */
class RequireStripeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (blank(config('cashier.webhook.secret'))) {
            abort(403, 'Set STRIPE_WEBHOOK_SECRET before pointing Stripe at this address.');
        }

        return $next($request);
    }
}
