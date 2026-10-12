<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Guests are reminded in the morning, local time, a few days before they
// arrive. withoutOverlapping guards against a slow run colliding with the next.
Schedule::command('bookings:send-check-in-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();
