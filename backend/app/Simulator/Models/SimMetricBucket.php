<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

class SimMetricBucket extends Model
{
    protected $table = 'sim_metric_buckets';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['histogram' => 'array', 'extra' => 'array', 'bucket_at' => 'datetime'];
    }
}
