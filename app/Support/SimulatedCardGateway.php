<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * A pretend card processor, standing in until the MCB gateway is integrated.
 *
 * Any well-formed card number is approved and no money moves - it is there to
 * show how paying works. Two numbers are kept back to demonstrate a decline.
 * Nothing about the card is kept beyond its brand and last four digits.
 */
class SimulatedCardGateway
{
    /**
     * Card numbers that are declined, and the message each one answers with.
     *
     * @var array<string, string>
     */
    public const DECLINED_CARDS = [
        '4000000000000002' => 'Your card was declined.',
        '4000000000009995' => 'Your card has insufficient funds.',
    ];

    /** Every reference this gateway hands out starts with this. */
    private const REFERENCE_PREFIX = 'SIM-';

    public static function enabled(): bool
    {
        return config('kozy.payments.card') === 'simulated';
    }

    /**
     * Strip the spaces and dashes people type between digit groups.
     */
    public static function normalise(string $number): string
    {
        return preg_replace('/\D/', '', $number) ?? '';
    }

    /**
     * A card number of plausible length whose check digit adds up (Luhn) -
     * the same first check a real gateway makes, which catches most typos.
     */
    public static function isValidNumber(string $number): bool
    {
        $digits = self::normalise($number);

        if (strlen($digits) < 13 || strlen($digits) > 19) {
            return false;
        }

        $sum = 0;

        foreach (str_split(strrev($digits)) as $position => $digit) {
            $value = (int) $digit * ($position % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }

    public static function brand(string $number): string
    {
        $digits = self::normalise($number);

        return match (true) {
            str_starts_with($digits, '4') => 'Visa',
            (bool) preg_match('/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/', $digits) => 'Mastercard',
            (bool) preg_match('/^3[47]/', $digits) => 'American Express',
            default => 'Card',
        };
    }

    /**
     * Charge a card.
     *
     * @return array{approved: bool, reference: string, brand: string, last4: string, message: string|null}
     */
    public function charge(string $number): array
    {
        $number = self::normalise($number);
        $decline = self::DECLINED_CARDS[$number] ?? null;

        return [
            'approved' => $decline === null,
            'reference' => self::REFERENCE_PREFIX.Str::upper(Str::random(12)),
            'brand' => self::brand($number),
            'last4' => substr($number, -4),
            'message' => $decline,
        ];
    }

    /**
     * Only a payment this gateway took can be refunded through it. A card
     * payment an admin recorded by hand has nothing here to reverse.
     */
    public function tookPayment(Payment $payment): bool
    {
        return $payment->method === Payment::METHOD_CARD
            && str_starts_with((string) $payment->transaction_ref, self::REFERENCE_PREFIX);
    }

    /**
     * Refund part or all of a payment, returning the refund's reference.
     */
    public function refund(Payment $payment, float $amount): string
    {
        return self::REFERENCE_PREFIX.'RF-'.Str::upper(Str::random(10));
    }
}
