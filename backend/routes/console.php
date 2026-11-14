<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Housekeeping that keeps projects tidy without anyone doing it by hand. Needs `php artisan schedule:work` (or cron).
Schedule::command('cycles:advance')->dailyAt('00:10');
Schedule::command('items:decay')->dailyAt('00:20');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
