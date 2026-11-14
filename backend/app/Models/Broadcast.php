<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $subject
 * @property string $body
 * @property string $audience
 * @property string|null $plan
 * @property bool $important
 * @property int $recipients
 */
#[Fillable(['subject', 'body', 'audience', 'plan', 'important', 'recipients', 'created_by', 'created_by_name'])]
class Broadcast extends Model
{
    public const AUDIENCES = ['everyone', 'admins', 'owners'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['important' => 'boolean'];
    }

    /** @return HasMany<OutgoingEmail, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(OutgoingEmail::class);
    }
}
