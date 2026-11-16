<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional drawing that describes a work item, next to its text description.
 * Kept in its own table: a scene is large, and lists and item reads never need it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_whiteboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->unique()->constrained()->cascadeOnDelete();
            // The Excalidraw scene as JSON, and the picture of it shown where the editor is not loaded.
            $table->longText('scene');
            $table->longText('preview_svg');
            // The words written on the board, for search, agents and AI help.
            $table->text('text')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_whiteboards');
    }
};
