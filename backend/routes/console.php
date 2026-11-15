<?php

use App\Models\OutgoingEmail;
use App\Support\Health;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Housekeeping that keeps projects tidy without anyone doing it by hand. Needs `php artisan schedule:work` (or cron).
// Proof of life for the health report.
Schedule::call(fn () => Health::beat())->everyMinute()->name('health-heartbeat');

Schedule::command('cycles:advance')->dailyAt('00:10');
Schedule::command('items:decay')->dailyAt('00:20');
// Announcements leave in small batches so a large one does not flood the mail server.
Schedule::command('mail:send-waiting')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [OutgoingEmail::class]])->dailyAt('00:30');
// Old simulation runs go, with every workspace and account they created.
Schedule::command('sim:prune')->dailyAt('00:40');
// A run whose runners died, or that is drowning the installation, is stopped without anyone watching.
Schedule::command('sim:watch')->everyMinute();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
