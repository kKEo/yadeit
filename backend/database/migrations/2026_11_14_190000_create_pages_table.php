<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Written knowledge next to the work: a page belongs to the workspace or to one project.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->longText('body')->nullable();
            $table->string('status', 20)->default('draft');
            // Set while the page can be read by anyone who has the link.
            $table->string('public_token', 64)->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
