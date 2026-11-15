<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $run_id
 * @property int $size
 * @property string $onboarding
 * @property string $name
 */
class SimTeam extends Model
{
    protected $table = 'sim_teams';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
