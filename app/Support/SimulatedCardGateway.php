<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * A pretend card processor, standing in until the MCB gateway is integrated.
 *
 * Only the test cards below are accepted. Anything else is refused before it
 * is looked at, so a guest who types a real card by mistake is told so rather
 * than "charged". No money moves either way, and nothing about the card is
 * kept beyond its brand and last four digits.
 */
class SimulatedCardGateway
{
    /**
     * Test card numbers, and the decline message each one answers with - null
     * for a card that is approved.
     *
     * @var array<string, array{brand: string, decline: string|null}>
     */
    public const TEST_CARDS = [
        '4242424242424242' => ['brand' => 'Visa', 'decline' => null],
        '5555555555554444' => ['brand' => 'Mastercard', 'decline' => null],
        '4000000000000002' => ['brand' => 'Visa', 'decline' => 'Your card was declined.'],
        '4000000000009995' => ['brand' => 'Visa', 'decline' => 'Your card has insufficient funds.'],
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

    public static function isTestCard(string $number): bool
    {
        return array_key_exists(self::normalise($number), self::TEST_CARDS);
    }

    /**
     * Charge a test card.
     *
     * @return array{approved: bool, reference: string, brand: string, last4: string, message: string|null}
     */
    public function charge(string $number): array
    {
        $number = self::normalise($number);
        $card = self::TEST_CARDS[$number];

        return [
            'approved' => $card['decline'] === null,
            'reference' => self::REFERENCE_PREFIX.Str::upper(Str::random(12)),
            'brand' => $card['brand'],
            'last4' => substr($number, -4),
            'message' => $card['decline'],
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
