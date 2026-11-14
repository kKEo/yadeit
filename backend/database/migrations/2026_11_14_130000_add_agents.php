<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An agent is a member that acts through tokens only. It belongs to one
        // workspace and has a human owner who answers for what it does.
        Schema::table('users', function (Blueprint $table) {
            $table->string('type', 10)->default('human');
            $table->string('description', 500)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['type', 'description']);
        });
    }
};
