<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    /**
     * Statuses that still hold nights for a property, i.e. everything but
     * cancellations.
     *
     * @var array<int, string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = ['property_id', 'client_id', 'check_in', 'check_out', 'guests', 'status', 'total_price'];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'total_price' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Bookings that hold nights for a property, i.e. everything but cancellations.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * Bookings whose stay overlaps the given half-open date range.
     *
     * A stay ending on the day another one starts is not an overlap. Dates are
     * normalised to Carbon so they compare as stored datetimes, not as strings.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeOverlapping(Builder $query, CarbonInterface|string $checkIn, CarbonInterface|string $checkOut): Builder
    {
        return $query
            ->where('check_in', '<', Carbon::parse($checkOut)->startOfDay())
            ->where('check_out', '>', Carbon::parse($checkIn)->startOfDay());
    }

    /**
     * A short human reference used across the back office instead of the raw id.
     */
    public function getReferenceAttribute(): string
    {
        return 'BK-'.str_pad((string) $this->getKey(), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Reference, property and guest in one line - what an admin needs to
     * recognise a booking in a table, a select field or a delete confirmation.
     */
    public function getLabelAttribute(): string
    {
        return sprintf(
            '%s · %s · %s',
            $this->reference,
            $this->property?->title ?? 'Unknown property',
            $this->client?->name ?? 'Unknown guest',
        );
    }

    /**
     * Bookings are priced in the currency of the property they belong to.
     */
    public function getCurrencyAttribute(): string
    {
        return $this->property?->currency ?: 'MUR';
    }

    public function getNightsAttribute(): int
    {
        if (! $this->check_in || ! $this->check_out) {
            return 0;
        }

        return (int) $this->check_in->diffInDays($this->check_out);
    }

    /**
     * Where the stay sits on the calendar, independent of the workflow status.
     */
    public function stage(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return 'cancelled';
        }

        if ($this->check_out && $this->check_out->isPast()) {
            return 'past';
        }

        if ($this->check_in && $this->check_in->isFuture()) {
            return 'upcoming';
        }

        return 'staying';
    }

    /**
     * Guests may drop a reservation until the day the stay starts.
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED], true)
            && $this->check_in?->isFuture();
    }

    public function isPayable(): bool
    {
        return $this->status !== self::STATUS_CANCELLED
            && $this->payment?->status !== Payment::STATUS_PAID;
    }

    public function isReviewable(): bool
    {
        return $this->status !== self::STATUS_CANCELLED
            && (bool) $this->check_out?->isPast()
            && ! $this->review;
    }
}
