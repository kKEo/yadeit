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
use Illuminate\Support\Str;

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
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['ai_enabled' => false, 'plan' => 'free'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // The workspace's own provider key is stored encrypted and never serialised.
        return ['ai_enabled' => 'boolean', 'ai_api_key' => 'encrypted', 'suspended_at' => 'datetime'];
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
