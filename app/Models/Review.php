<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $fillable = ['property_id', 'client_id', 'booking_id', 'rating', 'comment', 'status'];

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
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * A readable title for the admin panel, used instead of the record id.
     */
    public function getHeadlineAttribute(): string
    {
        return sprintf(
            '%d★ · %s · %s',
            (int) $this->rating,
            $this->property?->title ?? 'Unknown property',
            $this->client?->name ?? 'Unknown guest',
        );
    }
}
