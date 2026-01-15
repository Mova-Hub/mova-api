<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// This tells Laravel to check the 'jobs' table every minute.
// --stop-when-empty ensures the script doesn't hang and get killed by cPanel.
// --max-time=55 ensures it finishes before the next cron starts.
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();
