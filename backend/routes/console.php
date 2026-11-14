<?php

use App\Models\OutgoingEmail;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Housekeeping that keeps projects tidy without anyone doing it by hand. Needs `php artisan schedule:work` (or cron).
Schedule::command('cycles:advance')->dailyAt('00:10');
Schedule::command('items:decay')->dailyAt('00:20');
// Announcements leave in small batches so a large one does not flood the mail server.
Schedule::command('mail:send-waiting')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [OutgoingEmail::class]])->dailyAt('00:30');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
