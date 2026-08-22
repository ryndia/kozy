<?php

namespace App\Filament\Forms;

use App\Models\Booking;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * Select fields for the relationships that several resources share.
 *
 * They all pick records by name rather than by id: a reservation reads as
 * "BK-00012 · Sea-view villa · Alice Martin" instead of "12".
 */
class RelationshipSelects
{
    public static function property(string $name = 'property_id'): Select
    {
        return Select::make($name)
            ->label('Property')
            ->relationship('property', 'title')
            ->searchable()
            ->preload();
    }

    public static function client(string $name = 'client_id'): Select
    {
        return Select::make($name)
            ->label('Guest')
            ->relationship('client', 'name')
            ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} ({$record->email})")
            ->searchable(['name', 'email'])
            ->preload();
    }

    /**
     * Bookings have no single title column, so both the option labels and the
     * search have to be spelled out.
     */
    public static function booking(string $name = 'booking_id'): Select
    {
        return Select::make($name)
            ->label('Reservation')
            ->relationship('booking')
            ->getOptionLabelFromRecordUsing(fn (Booking $record): string => $record->label)
            ->getSearchResultsUsing(fn (string $search): array => static::searchBookings($search))
            ->searchable()
            ->preload();
    }

    /**
     * @return array<int, string>
     */
    protected static function searchBookings(string $search): array
    {
        $id = (int) preg_replace('/\D/', '', $search);

        return Booking::query()
            ->with(['property', 'client'])
            ->where(function (Builder $query) use ($search, $id): Builder {
                return $query
                    ->when($id > 0, fn (Builder $query): Builder => $query->orWhere('id', $id))
                    ->orWhereHas('property', fn (Builder $property) => $property->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$search}%"));
            })
            ->latest('id')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Booking $booking): array => [$booking->getKey() => $booking->label])
            ->all();
    }
}
