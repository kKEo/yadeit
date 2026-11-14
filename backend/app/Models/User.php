<?php

namespace App\Models;

use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passport\AccessToken;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/**
 * @property UserType $type
 * @property Carbon|null $email_verified_at
 * @property bool $is_operator
 * @property Carbon|null $suspended_at
 * @property-read User|null $owner
 */
#[Fillable(['name', 'email', 'password', 'locale', 'email_notifications'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const LOCALES = ['en', 'pl'];

    /** @var array<string, mixed> */
    protected $attributes = ['locale' => 'en', 'email_notifications' => true, 'type' => 'human'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'email_notifications' => 'boolean',
            'type' => UserType::class,
            'is_operator' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * What the request came with: an agent's access token, the stand-in a
     * browser session gets, an OAuth app's token, or nothing.
     *
     * @return PersonalAccessToken|TransientToken|AccessToken<mixed>|null
     */
    public function currentAccessToken()
    {
        return $this->accessToken;
    }

    public function isAgent(): bool
    {
        return $this->type === UserType::Agent;
    }

    /**
     * The human who answers for an agent.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'owner_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function membershipIn(Workspace $workspace): ?Membership
    {
        return $this->memberships()->where('workspace_id', $workspace->id)->first();
    }
}
