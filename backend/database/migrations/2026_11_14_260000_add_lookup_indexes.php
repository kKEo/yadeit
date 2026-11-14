<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns that lists and item pages look things up by.
 * PostgreSQL does not index a foreign key by itself, so "the checklist of
 * these items" or "what blocks this item" would otherwise read whole tables.
 */
return new class extends Migration
{
    private const INDEXES = [
        'work_items' => ['parent_id', 'cycle_id'],
        'work_item_relations' => ['related_item_id'],
        'checklist_items' => ['work_item_id'],
        'work_item_links' => ['work_item_id'],
        'attachments' => ['work_item_id'],
        'label_work_item' => ['label_id'],
        'subscriptions' => ['user_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    $blueprint->index($column, "{$table}_{$column}_lookup");
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex("{$table}_{$column}_lookup");
                }
            });
        }
    }
};
