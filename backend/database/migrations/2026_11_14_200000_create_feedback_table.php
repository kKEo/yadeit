<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Set while the project accepts feedback from anyone who has the form's link.
            $table->string('feedback_token', 64)->nullable()->unique();
        });

        Schema::table('work_items', function (Blueprint $table) {
            // How many pieces of feedback ask for this item. Kept as a number so lists can sort by it.
            $table->unsignedInteger('demand')->default(0);
        });

        // What people outside the team say they need. It waits here until someone decides what to do with it.
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('source', 20)->default('app');
            $table->string('status', 20)->default('new');
            $table->string('submitter_name', 100)->nullable();
            $table->string('submitter_email', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // The work item this feedback asks for, once accepted or merged.
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('feedback')->nullOnDelete();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->foreignId('triaged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('triaged_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'project_id', 'status']);
            $table->index('work_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
        Schema::table('work_items', fn (Blueprint $table) => $table->dropColumn('demand'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('feedback_token'));
    }
};
