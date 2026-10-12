<?php

namespace App\Support;

/**
 * What a cancellation would cost a guest, worked out before anyone commits to
 * it. The same quote is shown in the confirmation dialog, applied when the
 * cancellation goes through, and written onto the refund record.
 */
final readonly class CancellationQuote
{
    public const RULE_FULL = 'full';

    public const RULE_FEE = 'fee';

    public const RULE_FIRST_NIGHT = 'first_night';

    public const RULE_NONE = 'none';

    public const RULE_UNPAID = 'unpaid';

    public function __construct(
        /** What the guest actually paid, and so the most that can go back. */
        public float $paid,
        public float $fee,
        public float $refund,
        public string $currency,
        public int $daysNotice,
        public string $rule,
        public string $explanation,
    ) {}

    public function isFullRefund(): bool
    {
        return $this->paid > 0 && $this->refund >= $this->paid;
    }

    public function hasRefund(): bool
    {
        return $this->refund > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'paid' => $this->paid,
            'fee' => $this->fee,
            'refund' => $this->refund,
            'currency' => $this->currency,
            'days_notice' => $this->daysNotice,
            'rule' => $this->rule,
            'explanation' => $this->explanation,
            'full_refund' => $this->isFullRefund(),
        ];
    }
}
