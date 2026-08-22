<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = ['booking_id', 'amount', 'method', 'status', 'transaction_ref', 'paid_at', 'receipt_path'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
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
}
