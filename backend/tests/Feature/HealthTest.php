<?php

use App\Models\User;
use App\Support\Health;
use Illuminate\Support\Facades\DB;

it('answers only with the token, and not at all without one configured', function () {
    $this->getJson('/health')->assertNotFound();

    config(['health.token' => 'monitor-token-123']);
    $this->getJson('/health')->assertUnauthorized();
    $this->withToken('wrong')->getJson('/health')->assertUnauthorized();
});

it('reports failing parts with 503 and a healthy installation with 200', function () {
    config(['health.token' => 'monitor-token-123']);

    // No scheduler has run in this test.
    $this->withToken('monitor-token-123')->getJson('/health')
        ->assertStatus(503)->assertJsonPath('status', 'failing')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.scheduler.status', 'failing');

    Health::beat();
    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.backup.status', 'ok');

    // A job nobody picks up means the worker is down.
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(30)->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp()]);
    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.queue.status', 'failing');
    DB::table('jobs')->delete();

    // A failed job is worth a look, but nobody is affected right now.
    DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'warning')->assertJsonPath('checks.failed_jobs.status', 'warning');
});

it('watches the backup when it is told where the backup leaves its mark', function () {
    $file = storage_path('framework/testing/backup-heartbeat');
    @mkdir(dirname($file), recursive: true);
    @unlink($file);
    config(['health.token' => 't', 'health.backup_heartbeat' => $file]);
    Health::beat();

    $this->withToken('t')->getJson('/health')->assertStatus(503)->assertJsonPath('checks.backup.status', 'failing');

    touch($file);
    $this->getJson('/health')->assertOk()->assertJsonPath('checks.backup.status', 'ok');

    touch($file, now()->subDays(3)->getTimestamp());
    clearstatcache();
    $this->getJson('/health')->assertStatus(503);
    @unlink($file);
});

it('shows the report to operators and has a command for the shell', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/health')->assertNotFound();
    $this->actingAs(User::factory()->create(['is_operator' => true]))->getJson('/api/v1/admin/health')
        ->assertOk()->assertJsonPath('data.checks.storage.status', 'ok');

    $this->artisan('app:health')->assertFailed();
    Health::beat();
    $this->artisan('app:health')->expectsOutputToContain('scheduler')->assertSuccessful();
});
