<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Illuminate\Support\Facades\Schedule::command('reminders:send-booking')->hourly();

Illuminate\Support\Facades\Schedule::job(new \App\Jobs\CleanupMediaDownloadsJob)->dailyAt('04:00');

// ENABLED - 30-day deletion policy
Illuminate\Support\Facades\Schedule::command('images:cleanup-originals --days=30')
    ->daily()
    ->at('03:00')
    ->withoutOverlapping();

// Matterport Expiration and Reminder Check
Illuminate\Support\Facades\Schedule::command('matterport:check-expirations')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/matterport_expirations.log'));

