<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One run is one stretch of an agent's work on one item, from taking it to handing it back.
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['work_item_id', 'id']);
            $table->index(['agent_id', 'status']);
        });

        Schema::create('agent_run_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('body');
            $table->timestamp('created_at');

            $table->index(['agent_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_entries');
        Schema::dropIfExists('agent_runs');
    }
};
