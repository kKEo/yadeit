<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An app that a person connected over OAuth, and the agent of theirs it works as.
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_id');
            $table->string('client_name');
            $table->timestamps();

            // One app works as one agent per person; connecting again moves it.
            $table->unique(['user_id', 'client_id']);
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connections');
    }
};
