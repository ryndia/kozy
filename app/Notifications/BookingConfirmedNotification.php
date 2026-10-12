<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Pricing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Everything a guest needs about a stay they have just paid for: where it is,
 * when to arrive, what it cost, and the terms they agreed to.
 *
 * Queued, because it is sent from the payment settlement path - a mail server
 * having a slow minute must never hold up confirming a booking.
 */
class BookingConfirmedNotification extends Notification implements ShouldQueue
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
        $currency = $booking->currency;
        $vat = Pricing::vatBreakdown((float) $booking->total_price);

        $message = (new MailMessage)
            ->subject(sprintf('Your stay is confirmed - %s', $property->title ?? 'Kozy'))
            ->greeting(sprintf('Thank you, %s.', $notifiable->name ?? 'guest'))
            ->line(sprintf(
                'Your reservation **%s** at **%s** is confirmed.',
                $booking->reference,
                $property->title ?? 'your stay',
            ));

        if ($property?->address) {
            $message->line('**Address:** '.$property->address);
        }

        $message
            ->line(sprintf(
                '**Check in:** %s%s',
                $booking->check_in->format('l j F Y'),
                $property?->check_in ? ' from '.$property->check_in : '',
            ))
            ->line(sprintf(
                '**Check out:** %s%s',
                $booking->check_out->format('l j F Y'),
                $property?->check_out ? ' by '.$property->check_out : '',
            ))
            ->line(sprintf('**Guests:** %d · **Nights:** %d', $booking->guests, $booking->nights));

        // Listed prices include VAT, so the total is shown first and the tax is
        // itemised beneath it rather than added to it.
        $message
            ->line('---')
            ->line(sprintf('**Total paid:** %s %s', $currency, number_format($vat['total'], 2)))
            ->line(sprintf(
                'Includes %s %s of %s (net %s %s).',
                $currency,
                number_format($vat['vat'], 2),
                (string) config('kozy.vat.label', 'VAT'),
                $currency,
                number_format($vat['net'], 2),
            ));

        if ($booking->payment?->transaction_ref) {
            $message->line('**Payment reference:** '.$booking->payment->transaction_ref);
        }

        if ($property) {
            $message->line('---')
                ->line('**Cancellation policy**')
                ->line($this->asPlainText($property->cancellationPolicy()))
                ->line('**Terms and conditions**')
                ->line($this->asPlainText($property->terms()));
        }

        return $message
            ->action('View your booking', url('/client/bookings'))
            ->line('We look forward to hosting you.');
    }

    /**
     * Policies are authored as rich text in the back office. Mail lines render
     * as a paragraph, so the markup is flattened rather than shown raw.
     */
    private function asPlainText(string $value): string
    {
        $text = strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], "\n", $value));

        return trim(preg_replace('/\n{2,}/', "\n", html_entity_decode($text)) ?? '');
    }
}
