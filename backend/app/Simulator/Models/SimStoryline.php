<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

class SimStoryline extends Model
{
    protected $table = 'sim_storylines';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['intents' => 'array', 'created_at' => 'datetime'];
    }
}
