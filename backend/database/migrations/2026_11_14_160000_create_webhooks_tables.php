<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('url', 2000);
            // Encrypted; shown to its creator once.
            $table->text('secret');
            // Event names to send; null sends everything.
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            // Events in a row that could not be delivered; the webhook switches itself off at a limit.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });

        // One row per attempt, so the log shows every retry.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            // The same for every attempt of one event: receivers use it to ignore repeats.
            $table->uuid('event_id');
            $table->string('event', 60);
            $table->json('payload');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('succeeded')->default(false);
            $table->timestamp('created_at');

            $table->index(['webhook_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
