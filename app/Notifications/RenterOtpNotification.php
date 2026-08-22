<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RenterOtpNotification extends Notification
{
    use Queueable;

    public function __construct(protected string $code)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your KOZY verification code')
            ->line('Your 6-digit verification code is:')
            ->line("<strong>{$this->code}</strong>")
            ->line('Use this code to verify your email and continue your KOZY booking or sign-in flow.')
            ->line('This code expires in 5 minutes.');
    }
}
