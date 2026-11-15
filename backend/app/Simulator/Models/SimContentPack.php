<?php

namespace App\Simulator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $content
 */
class SimContentPack extends Model
{
    protected $table = 'sim_content_packs';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
