<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $password
 * @property string $name
 * @property string $persona
 * @property string $role
 * @property string $kind
 */
class SimMember extends Model
{
    protected $table = 'sim_members';

    protected $guarded = [];

    protected $hidden = ['password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }
}
