<?php

namespace App\Models;

use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money owed back to a guest, and whether it has actually reached them.
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'booking_id',
        'payment_id',
        'amount',
        'fee_amount',
        'currency',
        'status',
        'method',
        'provider_reference',
        'reason',
        'requested_by_id',
        'failure_reason',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Who asked for it. Null means the guest cancelled their own stay.
     *
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * Refunds still waiting to reach a guest - the admin payout queue.
     *
     * @param  Builder<Refund>  $query
     * @return Builder<Refund>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->where('amount', '>', 0);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getReferenceAttribute(): string
    {
        return 'RF-'.str_pad((string) $this->getKey(), 5, '0', STR_PAD_LEFT);
    }

    public function getLabelAttribute(): string
    {
        return sprintf('%s · %s', $this->reference, $this->booking->label ?? 'Unlinked booking');
    }
}
