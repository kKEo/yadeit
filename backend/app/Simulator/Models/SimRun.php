<?php

namespace App\Simulator\Models;

use App\Simulator\SimRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property SimRunStatus $status
 * @property array<string, mixed> $config
 * @property string $secret
 * @property int $speed
 * @property int $seed
 * @property int $ai_tokens_used
 * @property array<string, mixed>|null $summary
 * @property Carbon|null $finished_at
 * @property Carbon|null $started_at
 * @property string|null $runner_token_hash
 * @property string|null $stop_reason
 * @property string|null $verdict
 * @property string $name
 */
class SimRun extends Model
{
    protected $table = 'sim_runs';

    protected $guarded = [];

    protected $hidden = ['secret', 'runner_token_hash'];

    protected static function booted(): void
    {
        static::creating(function (SimRun $run): void {
            $run->secret ??= Str::random(48);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SimRunStatus::class,
            'config' => 'array',
            'summary' => 'array',
            'secret' => 'encrypted',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<SimTeam, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(SimTeam::class, 'run_id');
    }

    /** @return HasMany<SimMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SimMember::class, 'run_id');
    }

    /** The value of the X-Sim-Run header for one virtual client of this run. */
    public function headerFor(string $client): string
    {
        return "{$this->id}.{$client}.".$this->signature($client);
    }

    public function signature(string $client): string
    {
        return hash_hmac('sha256', "{$this->id}.{$client}", $this->secret);
    }
}
