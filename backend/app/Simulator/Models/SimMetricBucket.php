<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $count
 * @property int $errors
 * @property int $sum_ms
 * @property int $max_ms
 * @property array<array-key, int|float>|null $histogram
 * @property array<string, int|float>|null $extra
 * @property Carbon $bucket_at
 */
class SimMetricBucket extends Model
{
    protected $table = 'sim_metric_buckets';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['count' => 0, 'errors' => 0, 'sum_ms' => 0, 'max_ms' => 0];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['histogram' => 'array', 'extra' => 'array', 'bucket_at' => 'datetime'];
    }
}
