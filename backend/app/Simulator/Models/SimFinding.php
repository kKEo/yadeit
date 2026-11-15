<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

class SimFinding extends Model
{
    protected $table = 'sim_findings';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
