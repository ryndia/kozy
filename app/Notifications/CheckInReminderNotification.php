<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A nudge a few days before the stay: where to go, when to arrive, what is
 * still outstanding.
 */
class CheckInReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Booking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['property', 'payment']);
        $property = $booking->property;
        $days = (int) today()->diffInDays($booking->check_in, false);

        $message = (new MailMessage)
            ->subject(sprintf(
                'Your stay at %s starts %s',
                $property->title ?? 'Kozy',
                $days <= 1 ? 'tomorrow' : "in {$days} days",
            ))
            ->greeting(sprintf('See you soon, %s.', $notifiable->name ?? 'guest'))
            ->line(sprintf(
                'Your reservation **%s** at **%s** begins on **%s**.',
                $booking->reference,
                $property->title ?? 'your stay',
                $booking->check_in->format('l j F Y'),
            ));

        if ($property?->check_in) {
            $message->line('**Check in from:** '.$property->check_in);
        }

        if ($property?->address) {
            $message->line('**Address:** '.$property->address);
        }

        $message->line(sprintf('**Guests:** %d · **Nights:** %d', $booking->guests, $booking->nights));

        // A stay can start with the balance outstanding, so say so plainly
        // rather than letting the guest arrive to a surprise.
        if (! $booking->payment?->isPaid()) {
            $message->line(sprintf(
                '**Outstanding balance:** %s %s. Please settle it before you arrive.',
                $booking->currency,
                number_format((float) $booking->total_price, 2),
            ));
        }

        return $message
            ->action('View your booking', url('/client/bookings'))
            ->line('Safe travels.');
    }
}
