<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Guava\Calendar\Contracts\Eventable;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

class Booking extends Model implements Eventable
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    /**
     * Status colours for the back-office calendar, so a month view reads at a
     * glance without a legend.
     *
     * @var array<string, string>
     */
    public const CALENDAR_COLOURS = [
        self::STATUS_PENDING => '#d97706',
        self::STATUS_CONFIRMED => '#059669',
        self::STATUS_COMPLETED => '#2E6A9E',
        self::STATUS_CANCELLED => '#e11d48',
    ];

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
        'confirmation_sent_at' => 'datetime',
        'check_in_reminder_sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
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
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->latest();
    }

    /**
     * Who cancelled this stay. Null means the guest did it themselves.
     *
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
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

    /**
     * How this stay appears on the back-office calendar.
     *
     * The end date is exclusive, which matches how a stay is stored: a booking
     * that checks out on the 10th occupies up to the 9th, and the 10th is free
     * for the next guest.
     */
    public function toCalendarEvent(): CalendarEvent
    {
        return CalendarEvent::make($this)
            ->title(sprintf(
                '%s · %s',
                $this->property->title ?? 'Unknown property',
                $this->client->name ?? 'Unknown guest',
            ))
            ->start($this->check_in)
            ->end($this->check_out)
            ->allDay()
            ->backgroundColor(self::CALENDAR_COLOURS[$this->status])
            ->textColor('#ffffff')
            // Clicking a stay opens it. Guarded, so the model stays usable if
            // the admin panel's routes are not loaded.
            ->url(
                Route::has('filament.admin.resources.bookings.edit')
                    ? route('filament.admin.resources.bookings.edit', ['record' => $this->getKey()])
                    : url("/admin/bookings/{$this->getKey()}/edit"),
                '_self',
            );
    }
}
