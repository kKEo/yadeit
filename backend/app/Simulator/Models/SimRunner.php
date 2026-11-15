<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

class SimRunner extends Model
{
    protected $table = 'sim_runners';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array', 'last_seen_at' => 'datetime'];
    }
}
