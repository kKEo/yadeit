<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

/**
 * @property bool $ai_enabled
 * @property string $plan
 * @property Carbon|null $suspended_at
 * @property string|null $ai_api_key
 * @property int|null $ai_monthly_token_limit
 */
#[Fillable(['name', 'slug', 'created_by'])]
#[Hidden(['ai_api_key'])]
class Workspace extends Model
{
    use Billable;

    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // The database removes the workspace's rows by itself; the uploaded files have to be removed here.
        static::deleted(fn (Workspace $workspace) => Storage::disk('local')->deleteDirectory("attachments/{$workspace->id}"));
    }

    /** Stripe knows the workspace by its name and reaches its first owner. */
    public function stripeName(): string
    {
        return $this->name;
    }

    /** The paid plan at Stripe, when there is one. */
    public function planSubscription(): ?PlanSubscription
    {
        $subscription = $this->subscription('default');

        return $subscription instanceof PlanSubscription ? $subscription : null;
    }

    public function stripeEmail(): ?string
    {
        return User::query()->whereIn('id', $this->memberships()->where('role', 'owner')->select('user_id'))->orderBy('id')->value('email');
    }

    /** @var array<string, mixed> */
    protected $attributes = ['ai_enabled' => false, 'plan' => 'free'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // The workspace's own provider key is stored encrypted and never serialised.
        return ['ai_enabled' => 'boolean', 'ai_api_key' => 'encrypted', 'suspended_at' => 'datetime', 'trial_ends_at' => 'datetime'];
    }

    /** Slugs that would collide with application routes. */
    public const RESERVED_SLUGS = ['new', 'admin', 'api', 'login', 'register', 'settings', 'invitations', 'w'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $base = Str::limit($base, 40, '');

        if (in_array($base, self::RESERVED_SLUGS, true)) {
            $base .= '-team';
        }

        $slug = $base;
        $suffix = 2;

        while (self::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
