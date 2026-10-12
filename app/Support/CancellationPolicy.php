<?php

namespace App\Support;

use App\Models\Booking;
use Carbon\CarbonInterface;

/**
 * Works out what a guest gets back when they cancel.
 *
 * The rule is notice-based: the further ahead of check-in a stay is dropped,
 * the more comes back. Thresholds are configured once in `kozy.policies`, while
 * the percentage kept in the middle tier belongs to the property, so owners can
 * set their own without each needing their own schedule.
 */
final class CancellationPolicy
{
    /**
     * @param  CarbonInterface|null  $at  When the cancellation happens; defaults to now.
     */
    public static function quote(Booking $booking, ?CarbonInterface $at = null): CancellationQuote
    {
        $at = $at ?? now();
        $currency = $booking->currency;
        $payment = $booking->payment;

        // Only settled money can come back. An unpaid booking simply releases
        // its nights - there is nothing to keep and nothing to return.
        $paid = $payment && $payment->isPaid()
            ? round((float) $payment->amount - (float) $payment->refunded_amount, 2)
            : 0.0;

        $daysNotice = self::daysNotice($booking, $at);

        if ($paid <= 0) {
            return new CancellationQuote(
                paid: 0.0,
                fee: 0.0,
                refund: 0.0,
                currency: $currency,
                daysNotice: $daysNotice,
                rule: CancellationQuote::RULE_UNPAID,
                explanation: 'Nothing has been paid for this reservation, so there is nothing to refund.',
            );
        }

        // A stay that has already begun matches no tier.
        if ($daysNotice < 0) {
            return new CancellationQuote(
                paid: $paid,
                fee: $paid,
                refund: 0.0,
                currency: $currency,
                daysNotice: $daysNotice,
                rule: CancellationQuote::RULE_NONE,
                explanation: 'The stay has already started, so this cancellation is not refunded.',
            );
        }

        [$rule, $fee] = self::feeFor($booking, $daysNotice, $paid);

        $fee = min(round($fee, 2), $paid);
        $refund = round($paid - $fee, 2);

        return new CancellationQuote(
            paid: $paid,
            fee: $fee,
            refund: $refund,
            currency: $currency,
            daysNotice: $daysNotice,
            rule: $rule,
            explanation: self::explain($rule, $daysNotice, $booking),
        );
    }

    /**
     * Whole days between the cancellation and the first night. Negative once
     * the stay has started.
     */
    public static function daysNotice(Booking $booking, ?CarbonInterface $at = null): int
    {
        return (int) ($at ?? now())->startOfDay()->diffInDays(
            $booking->check_in->startOfDay(),
            false,
        );
    }

    /**
     * @return array{0: string, 1: float}
     */
    private static function feeFor(Booking $booking, int $daysNotice, float $paid): array
    {
        foreach (self::tiers() as $tier) {
            if ($daysNotice < (int) $tier['min_days_notice']) {
                continue;
            }

            return match ($tier['rule']) {
                CancellationQuote::RULE_FEE => [
                    CancellationQuote::RULE_FEE,
                    $paid * ($booking->property?->cancellationFeePercent() ?? 0) / 100,
                ],
                CancellationQuote::RULE_FIRST_NIGHT => [
                    CancellationQuote::RULE_FIRST_NIGHT,
                    self::oneNight($booking, $paid),
                ],
                default => [CancellationQuote::RULE_FULL, 0.0],
            };
        }

        // No tier matched, which only happens if every threshold is above the
        // notice given. Keeping everything is the safe reading of that.
        return [CancellationQuote::RULE_NONE, $paid];
    }

    /**
     * One night's share of what was paid, rather than the listed rate: a stay
     * booked at a discount should not forfeit more than it cost.
     */
    private static function oneNight(Booking $booking, float $paid): float
    {
        $nights = max(1, $booking->nights);

        return $paid / $nights;
    }

    private static function explain(string $rule, int $daysNotice, Booking $booking): string
    {
        $percent = rtrim(rtrim(number_format(
            $booking->property?->cancellationFeePercent() ?? 0, 2, '.', ''
        ), '0'), '.');

        return match ($rule) {
            CancellationQuote::RULE_FULL => sprintf(
                'Cancelled %d days before check-in, so the full amount is refunded.',
                $daysNotice,
            ),
            CancellationQuote::RULE_FEE => sprintf(
                'Cancelled %d days before check-in, so a %s%% cancellation fee applies.',
                $daysNotice,
                $percent,
            ),
            CancellationQuote::RULE_FIRST_NIGHT => sprintf(
                'Cancelled %d day%s before check-in, so the first night is not refunded.',
                $daysNotice,
                $daysNotice === 1 ? '' : 's',
            ),
            default => 'This cancellation is not refunded.',
        };
    }

    /**
     * The configured tiers, normalised so a hand-edited config file cannot put
     * a half-formed entry into the middle of a money calculation.
     *
     * @return array<int, array{min_days_notice: int, rule: string}>
     */
    private static function tiers(): array
    {
        $tiers = [];

        foreach ((array) config('kozy.policies.cancellation_tiers', []) as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $tiers[] = [
                'min_days_notice' => (int) ($tier['min_days_notice'] ?? 0),
                'rule' => (string) ($tier['rule'] ?? CancellationQuote::RULE_FULL),
            ];
        }

        // Most notice first, so the first match is the most generous tier the
        // guest qualifies for however the config happens to be ordered.
        usort($tiers, fn (array $a, array $b): int => $b['min_days_notice'] <=> $a['min_days_notice']);

        return $tiers;
    }
}
