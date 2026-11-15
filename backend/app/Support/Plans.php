<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** What a workspace's plan allows, and the check that refuses what it does not. */
class Plans
{
    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys((array) config('plans.plans'));
    }

    public static function enforced(): bool
    {
        return (bool) config('plans.enforce');
    }

    /** Paid plans can be bought only when Stripe is set up. */
    public static function billingEnabled(): bool
    {
        return filled(config('cashier.secret'));
    }

    /** @return array{name: string, members: int|null, projects: int|null, ai_tokens: int|null, stripe_price: string|null} */
    public static function of(Workspace $workspace): array
    {
        $plans = (array) config('plans.plans');

        return $plans[$workspace->plan] ?? $plans[(string) config('plans.default')];
    }

    /** The plan that a Stripe price buys, or null for a price we do not sell. */
    public static function keyForPrice(?string $price): ?string
    {
        foreach ((array) config('plans.plans') as $key => $plan) {
            if ($price !== null && ($plan['stripe_price'] ?? null) === $price) {
                return (string) $key;
            }
        }

        return null;
    }

    /** @return array{members: int, projects: int} */
    public static function usage(Workspace $workspace): array
    {
        return [
            // People only, open invitations included: an agent is not a seat.
            'members' => DB::table('workspace_memberships')
                ->join('users', 'users.id', '=', 'workspace_memberships.user_id')
                ->where('workspace_memberships.workspace_id', $workspace->id)->where('users.type', 'human')->count()
                + DB::table('workspace_invitations')->where('workspace_id', $workspace->id)
                    ->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            'projects' => DB::table('projects')->where('workspace_id', $workspace->id)->whereNull('archived_at')->count(),
        ];
    }

    /** Refuses with 402 when one more person or project would exceed the plan. */
    public static function ensureRoom(Workspace $workspace, string $what): void
    {
        // A simulated team is as large as its scenario says, whatever plan the workspace shows.
        $limit = self::enforced() && $workspace->sim_run_id === null ? (self::of($workspace)[$what] ?? null) : null;

        if ($limit === null) {
            return;
        }

        $used = self::usage($workspace)[$what];

        if ($used >= $limit) {
            $plan = self::of($workspace)['name'];
            $thing = $what === 'members' ? 'people (open invitations included)' : 'projects';

            throw new ApiException(402, 'plan_limit_reached',
                "The {$plan} plan allows {$limit} {$thing}, and this workspace has {$used}. An owner can change the plan under Settings.",
                ['limit' => $limit, 'used' => $used, 'what' => $what, 'plan' => $workspace->plan]);
        }
    }

    /** The plan's monthly AI allowance, when limits apply. A workspace paying for its own key is not capped by it. */
    public static function aiTokens(Workspace $workspace): ?int
    {
        return self::enforced() && $workspace->ai_api_key === null ? self::of($workspace)['ai_tokens'] : null;
    }
}
