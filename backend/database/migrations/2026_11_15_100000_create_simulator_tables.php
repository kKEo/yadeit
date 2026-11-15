<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Traffic lab (docs/traffic-lab.md): scenarios, runs, the teams and members of a run, and what a run measured.
return new class extends Migration
{
    public function up(): void
    {
        // Set on everything a simulation created, so it can be kept out of statistics, mailings and billing, and removed afterwards.
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('sim_run_id')->nullable()->index());
        Schema::table('workspaces', fn (Blueprint $table) => $table->unsignedBigInteger('sim_run_id')->nullable()->index());

        Schema::create('sim_scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->json('config');
            $table->timestamps();
        });

        Schema::create('sim_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->string('name', 80);
            $table->string('status', 20)->index();
            // A copy of the scenario as it was when the run started.
            $table->json('config');
            $table->unsignedInteger('speed')->default(1);
            $table->unsignedBigInteger('seed')->default(1);
            // Signs the header that marks a request as simulated; stored encrypted.
            $table->text('secret');
            // Runners present this token; only its hash is kept.
            $table->string('runner_token_hash', 64)->nullable();
            $table->unsignedBigInteger('ai_tokens_used')->default(0);
            $table->string('stop_reason', 200)->nullable();
            $table->string('verdict', 10)->nullable();
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sim_runners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('sim_runs')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('host', 120)->nullable();
            $table->unsignedInteger('lag_ms')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sim_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('sim_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('runner_id')->nullable();
            $table->string('name', 80);
            $table->unsignedTinyInteger('size');
            // "fast": created directly; "real": the members sign up and invite each other through the API.
            $table->string('onboarding', 10)->default('fast');
            $table->string('status', 20)->default('planned');
            $table->json('state')->nullable();
            $table->timestamps();
        });

        Schema::create('sim_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('sim_runs')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('sim_teams')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 100);
            $table->string('persona', 20);
            $table->string('role', 10);
            // scripted, ai (an explorer) or agent.
            $table->string('kind', 10)->default('scripted');
            $table->string('email')->index();
            // Only ever a generated password of a simulated account; stored encrypted so a runner can sign in with it.
            $table->text('password');
            $table->timestamps();
        });

        // Mail to simulated people ends here instead of leaving, so that sign-up and invitation links can be followed.
        Schema::create('sim_mailbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->nullable()->index();
            $table->string('to_email')->index();
            $table->string('kind', 60);
            $table->string('subject')->nullable();
            $table->json('links');
            $table->timestamp('created_at');
        });

        // What the runners measured, summed per ten seconds.
        Schema::create('sim_metric_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('sim_runs')->cascadeOnDelete();
            $table->timestamp('bucket_at');
            // action, endpoint, flow or server.
            $table->string('kind', 10);
            $table->string('name', 120);
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedBigInteger('errors')->default(0);
            $table->unsignedBigInteger('sum_ms')->default(0);
            $table->unsignedInteger('max_ms')->default(0);
            $table->json('histogram')->nullable();
            $table->json('extra')->nullable();

            $table->unique(['run_id', 'bucket_at', 'kind', 'name']);
        });

        Schema::create('sim_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('sim_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('flow', 40);
            $table->string('step', 60)->nullable();
            $table->string('severity', 10)->default('error');
            $table->string('message', 500);
            $table->json('details')->nullable();
            $table->timestamp('created_at');

            $table->index(['run_id', 'id']);
        });

        Schema::create('sim_content_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('theme', 500)->nullable();
            $table->string('source', 10)->default('ai');
            $table->json('content');
            $table->timestamps();
        });

        Schema::create('sim_storylines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('sim_runs')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('sim_teams')->cascadeOnDelete();
            $table->unsignedInteger('sim_day');
            $table->json('intents');
            $table->timestamp('created_at');

            $table->unique(['team_id', 'sim_day']);
        });
    }

    public function down(): void
    {
        foreach (['sim_storylines', 'sim_content_packs', 'sim_findings', 'sim_metric_buckets', 'sim_mailbox', 'sim_members', 'sim_teams', 'sim_runners', 'sim_runs', 'sim_scenarios'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('sim_run_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('sim_run_id'));
    }
};
