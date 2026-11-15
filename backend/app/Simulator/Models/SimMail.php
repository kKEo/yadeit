<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

class SimMail extends Model
{
    protected $table = 'sim_mailbox';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['links' => 'array', 'created_at' => 'datetime'];
    }
}
