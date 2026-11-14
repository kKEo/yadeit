<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Length of a cycle in weeks; null means the project does not use cycles.
            $table->unsignedTinyInteger('cycle_weeks')->nullable();
            // Open items untouched for this many days fall back to the backlog; null turns it off.
            $table->unsignedSmallInteger('stale_after_days')->nullable();
        });

        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->date('starts_on');
            $table->date('ends_on');
            // Set when the cycle was closed and its unfinished items moved on.
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('cycle_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('cycle_id'));
        Schema::dropIfExists('cycles');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['cycle_weeks', 'stale_after_days']));
    }
};
