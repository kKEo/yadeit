<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications')->default(true);
        });

        // A view is a saved query plus how to show it.
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_shared')->default(false);
            $table->json('filters');
            $table->string('layout', 10)->default('list');
            $table->string('group_by', 20)->default('state');
            $table->string('sort', 30)->default('-updated_at');
            $table->timestamps();

            $table->index(['workspace_id', 'owner_id']);
        });

        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 30);
            $table->json('details')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'workspace_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
        Schema::dropIfExists('saved_views');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_notifications');
        });
    }
};
