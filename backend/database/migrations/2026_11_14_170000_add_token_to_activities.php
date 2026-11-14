<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which access token made the change. The name is kept so the history
        // still reads well after the token is revoked.
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('token_name', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['token_id', 'token_name']);
        });
    }
};
