<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const METHOD_CARD = 'card';

    public const METHOD_MANUAL = 'manual';

    protected $fillable = [
        'booking_id',
        'amount',
        'refunded_amount',
        'method',
        'status',
        'transaction_ref',
        'provider_payload',
        'paid_at',
        'receipt_path',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'provider_payload' => 'array',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * A short human reference used across the back office instead of the raw id.
     */
    public function getReferenceAttribute(): string
    {
        return 'PM-'.str_pad((string) $this->getKey(), 5, '0', STR_PAD_LEFT);
    }

    public function getLabelAttribute(): string
    {
        return sprintf('%s · %s', $this->reference, $this->booking?->label ?? 'Unlinked booking');
    }

    public function getCurrencyAttribute(): string
    {
        return $this->booking?->currency ?: 'MUR';
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * What could still be given back: the amount paid, less anything already
     * refunded.
     */
    public function refundableAmount(): float
    {
        return $this->isPaid() || $this->status === self::STATUS_REFUNDED
            ? max(0, round((float) $this->amount - (float) $this->refunded_amount, 2))
            : 0.0;
    }
}
