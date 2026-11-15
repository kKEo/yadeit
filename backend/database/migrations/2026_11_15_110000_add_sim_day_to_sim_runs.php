<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The last simulated day whose midnight (cycle rollover, stale items) has been played.
        Schema::table('sim_runs', fn (Blueprint $table) => $table->unsignedInteger('sim_day')->default(0));
    }

    public function down(): void
    {
        Schema::table('sim_runs', fn (Blueprint $table) => $table->dropColumn('sim_day'));
    }
};
