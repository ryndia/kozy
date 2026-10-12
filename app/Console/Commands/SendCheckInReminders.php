<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\CheckInReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mails guests whose stay starts soon.
 *
 * Runs daily. Each booking is stamped once sent, so a second run on the same
 * day - or a re-run after a failure - never mails the same guest twice.
 */
class SendCheckInReminders extends Command
{
    protected $signature = 'bookings:send-check-in-reminders
                            {--days= : Days before check-in to remind (defaults to the configured lead time)}
                            {--dry-run : List who would be mailed without sending anything}';

    protected $description = 'Email guests whose stay is about to start';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('kozy.reminders.check_in_days', 3));
        $dryRun = (bool) $this->option('dry-run');

        $bookings = Booking::query()
            ->with(['client', 'property', 'payment'])
            ->active()
            ->whereNull('check_in_reminder_sent_at')
            // Between today and the lead time: a stay starting today still
            // wants a reminder, one that already started no longer does.
            ->whereDate('check_in', '>=', today())
            ->whereDate('check_in', '<=', today()->addDays($days))
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('No check-in reminders are due.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($bookings as $booking) {
            if (! $booking->client) {
                $this->warn("{$booking->reference} has no guest to mail.");

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '%s · %s · checks in %s',
                    $booking->reference,
                    $booking->client->email,
                    $booking->check_in->toDateString(),
                ));

                continue;
            }

            try {
                $booking->client->notify(new CheckInReminderNotification($booking));
                $booking->forceFill(['check_in_reminder_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $exception) {
                // One bad address must not stop the rest of the run.
                Log::error('Could not send a check-in reminder.', [
                    'booking_id' => $booking->getKey(),
                    'message' => $exception->getMessage(),
                ]);

                $this->error("{$booking->reference}: {$exception->getMessage()}");
            }
        }

        $this->info($dryRun
            ? sprintf('%d reminder(s) would be sent.', $bookings->count())
            : sprintf('Sent %d check-in reminder(s).', $sent));

        return self::SUCCESS;
    }
}
