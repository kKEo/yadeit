<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $last_seen_at
 */
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
