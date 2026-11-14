<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // People who run the installation. Granted from the command line only.
            $table->boolean('is_operator')->default(false);
            $table->timestamp('suspended_at')->nullable();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->string('plan', 20)->default('free');
        });

        // Who did what, for the things that matter after the fact: access, sharing, settings, deletions.
        // No foreign keys: the record must outlive the workspace and the person it is about.
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('workspace_slug')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_type', 10)->nullable();
            $table->string('token_name', 80)->nullable();
            $table->string('action', 60);
            $table->string('subject', 200)->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['workspace_id', 'id']);
            $table->index(['action', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn(['suspended_at', 'plan']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_operator', 'suspended_at']));
    }
};
