<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Expire pending bookings that have not been confirmed within 24 hours.
 * Run this in production via system cron:
 *
 *   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
 *
 * Override the expiration window without changing this file:
 *   BOOKING_EXPIRY_HOURS=48  (add to .env, then read below if customizing further)
 */
Schedule::command('bookings:expire --hours=24')->hourly();
