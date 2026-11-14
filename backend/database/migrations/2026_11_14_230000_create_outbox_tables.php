<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An announcement from the operator to the people using the installation.
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 150);
            $table->text('body');
            $table->string('audience', 20);
            $table->string('plan', 20)->nullable();
            // A service notice also reaches people who turned email notifications off.
            $table->boolean('important')->default(false);
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        // Every email the installation sends: announcements wait here to be sent,
        // everything else is recorded once it has gone out.
        Schema::create('outgoing_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 60);
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject', 255);
            $table->string('status', 10);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['broadcast_id', 'status']);
            $table->index('to_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_emails');
        Schema::dropIfExists('broadcasts');
    }
};
