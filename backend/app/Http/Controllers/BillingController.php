<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Services\Billing;
use App\Support\CurrentWorkspace;
use App\Support\Plans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The workspace's plan: what it allows, how much is used, and the way to a paid plan. */
class BillingController extends Controller
{
    public function __construct(private Billing $billing, private CurrentWorkspace $current) {}

    /** The plan with its limits and the current usage. For admins and owners. */
    public function show(Workspace $workspace): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Admin);

        $plan = Plans::of($workspace);
        $subscription = Plans::billingEnabled() ? $workspace->planSubscription() : null;

        return response()->json(['data' => [
            'plan' => array_key_exists($workspace->plan, (array) config('plans.plans')) ? $workspace->plan : (string) config('plans.default'),
            // Off on installations without paid plans: the limits are then shown nowhere and refuse nothing.
            'limits_enforced' => Plans::enforced(),
            'billing_enabled' => Plans::billingEnabled(),
            'limits' => ['members' => $plan['members'], 'projects' => $plan['projects'], 'ai_tokens' => $plan['ai_tokens']],
            'usage' => Plans::usage($workspace),
            'plans' => collect((array) config('plans.plans'))->map(fn (array $p, string $key) => [
                'key' => $key,
                'name' => $p['name'],
                'members' => $p['members'],
                'projects' => $p['projects'],
                'ai_tokens' => $p['ai_tokens'],
                'purchasable' => Plans::billingEnabled() && filled($p['stripe_price']),
            ])->values(),
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->stripe_status,
                // Set when the plan was cancelled and runs until this date.
                'ends_at' => $subscription->ends_at,
            ],
            'has_billing_account' => $workspace->hasStripeId(),
        ]]);
    }

    /** Starts buying a plan: answers with the address of the payment page. Owners only. */
    public function checkout(Request $request, Workspace $workspace): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Owner);
        $data = $request->validate(['plan' => ['required', Rule::in(Plans::keys())]]);

        return response()->json(['data' => ['url' => $this->billing->checkoutUrl($workspace, $data['plan'])]]);
    }

    /** The address of the billing portal: invoices, payment method, cancelling. Owners only. */
    public function portal(Workspace $workspace): JsonResponse
    {
        $this->current->requireRole(WorkspaceRole::Owner);

        return response()->json(['data' => ['url' => $this->billing->portalUrl($workspace)]]);
    }
}
