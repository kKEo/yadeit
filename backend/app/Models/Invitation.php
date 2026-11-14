<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property WorkspaceRole $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
#[Fillable(['workspace_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    protected $table = 'workspace_invitations';

    public const VALID_DAYS = 7;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findUsableByToken(string $token): ?self
    {
        return self::query()
            ->where('token_hash', self::hashToken($token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
