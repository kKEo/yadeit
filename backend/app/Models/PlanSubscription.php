<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription;

/**
 * A workspace's paid plan at Stripe. Cashier's subscription under a name that
 * does not clash with following an item.
 *
 * @property string $stripe_status
 * @property string|null $stripe_price
 * @property Carbon|null $ends_at
 */
class PlanSubscription extends Subscription {}
