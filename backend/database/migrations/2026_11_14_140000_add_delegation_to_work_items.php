<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            // The agent doing the work. The assignee stays the person who answers for it.
            $table->foreignId('delegate_id')->nullable()->constrained('users')->nullOnDelete();
            // Work is paused until a person answers a question on the item.
            $table->boolean('needs_input')->default(false);

            $table->index(['workspace_id', 'delegate_id']);
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'delegate_id']);
            $table->dropConstrainedForeignId('delegate_id');
            $table->dropColumn('needs_input');
        });
    }
};
