<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

class SimScenario extends Model
{
    protected $table = 'sim_scenarios';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['config' => 'array'];
    }
}
