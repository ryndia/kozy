<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Refund;
use App\Support\CancellationQuote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a cancellation and, more importantly, says exactly what is coming
 * back and why anything was kept.
 */
class BookingCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Booking $booking,
        protected CancellationQuote $quote,
        protected ?Refund $refund = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing('property');
        $currency = $this->quote->currency;

        $message = (new MailMessage)
            ->subject(sprintf('Your reservation %s has been cancelled', $booking->reference))
            ->greeting(sprintf('Hello %s,', $notifiable->name ?? 'there'))
            ->line(sprintf(
                'Your reservation **%s** at **%s**, for %s, has been cancelled.',
                $booking->reference,
                $booking->property->title ?? 'your stay',
                $booking->check_in->format('j F Y'),
            ));

        if ($booking->cancellation_reason) {
            $message->line('**Reason:** '.$booking->cancellation_reason);
        }

        if ($this->quote->paid <= 0) {
            return $message
                ->line('No payment had been taken for this reservation, so there is nothing to refund.')
                ->action('Browse other stays', url('/properties'))
                ->line('We hope to host you another time.');
        }

        $message
            ->line('---')
            ->line(sprintf('**Paid:** %s %s', $currency, number_format($this->quote->paid, 2)));

        if ($this->quote->fee > 0) {
            $message
                ->line(sprintf(
                    '**Cancellation fee:** %s %s',
                    $currency,
                    number_format($this->quote->fee, 2),
                ))
                ->line($this->quote->explanation);
        }

        $message->line(sprintf(
            '**Refund:** %s %s',
            $currency,
            number_format($this->quote->refund, 2),
        ));

        // Set expectations honestly: a card refund is already on its way, a
        // returned bank transfer is not.
        if ($this->quote->hasRefund()) {
            $message->line($this->refund?->isCompleted()
                ? 'The refund has been issued and usually reaches your account within 5 to 10 working days.'
                : 'Our team is processing your refund and it will reach you within 5 to 10 working days.');
        }

        return $message
            ->action('View your bookings', url('/client/bookings'))
            ->line('We hope to host you another time.');
    }
}
