<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Off until an admin switches it on: with AI on, text of the workspace is sent to the model provider.
            $table->boolean('ai_enabled')->default(false);
            // The workspace's own provider key, encrypted. Null means the instance's key is used.
            $table->text('ai_api_key')->nullable();
            $table->unsignedBigInteger('ai_monthly_token_limit')->nullable();
        });

        // One row per call to the model, for the monthly limit and for seeing where tokens go.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 40);
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->timestamp('created_at');

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn(['ai_enabled', 'ai_api_key', 'ai_monthly_token_limit']));
    }
};
