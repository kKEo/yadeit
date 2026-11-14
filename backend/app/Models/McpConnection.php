<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Passport;

/**
 * @property int $id
 * @property int $user_id
 * @property int $agent_id
 * @property string $client_id
 * @property string $client_name
 * @property-read User|null $agent
 */
#[Fillable(['user_id', 'agent_id', 'client_id', 'client_name'])]
class McpConnection extends Model
{
    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** Ends the connection: the app's access and refresh tokens stop working at once. */
    public function revoke(): void
    {
        $tokens = Passport::token()->newQuery()->where('user_id', $this->user_id)->where('client_id', $this->client_id);

        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);

        $this->delete();
    }
}
