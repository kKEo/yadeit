<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portable on purpose: runs unchanged on PostgreSQL and MariaDB.
 * Every tenant table carries workspace_id for the BelongsToWorkspace scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('next_sequence')->default(1);
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('group', 20);
            $table->string('color', 7)->default('#94a3b8');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 7)->default('#94a3b8');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('title', 255);
            $table->text('description')->nullable();
            // Cascade so that deleting a project works on MariaDB, which checks row by row.
            // The API refuses to delete a state that is still in use.
            $table->foreignId('state_id')->constrained('states')->cascadeOnDelete();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('kind', 20)->default('task');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'sequence']);
            $table->index(['workspace_id', 'state_id']);
            $table->index(['workspace_id', 'assignee_id']);
            $table->index(['workspace_id', 'updated_at']);
        });

        Schema::create('label_work_item', function (Blueprint $table) {
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();

            $table->primary(['work_item_id', 'label_id']);
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('text', 500);
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('work_item_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('url', 2000);
            $table->string('title', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('work_item_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->string('type', 20);
            $table->timestamps();

            $table->unique(['work_item_id', 'related_item_id', 'type']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['work_item_id', 'id']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('diff')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['work_item_id', 'id']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['work_item_id', 'user_id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status');
            $table->longText('body');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach ([
            'idempotency_keys', 'attachments', 'subscriptions', 'activities', 'comments',
            'work_item_relations', 'work_item_links', 'checklist_items', 'label_work_item',
            'work_items', 'labels', 'states',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['next_sequence', 'archived_at']);
        });
    }
};
