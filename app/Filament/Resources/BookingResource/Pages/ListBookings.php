<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New booking'),
        ];
    }

    /**
     * The stages an admin works through day to day, each with a live count.
     */
    public function getTabs(): array
    {
        $today = Carbon::today();

        return [
            'all' => Tab::make('All')
                ->badge(Booking::query()->count()),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Booking::STATUS_PENDING))
                ->badge(Booking::query()->where('status', Booking::STATUS_PENDING)->count())
                ->badgeColor('warning'),

            'in_house' => Tab::make('In house')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', Booking::ACTIVE_STATUSES)
                    ->whereDate('check_in', '<=', $today)
                    ->whereDate('check_out', '>', $today))
                ->badge(Booking::query()
                    ->active()
                    ->whereDate('check_in', '<=', $today)
                    ->whereDate('check_out', '>', $today)
                    ->count())
                ->badgeColor('success'),

            'upcoming' => Tab::make('Upcoming')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', Booking::ACTIVE_STATUSES)
                    ->whereDate('check_in', '>', $today))
                ->badge(Booking::query()->active()->whereDate('check_in', '>', $today)->count()),

            'past' => Tab::make('Past')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', Booking::ACTIVE_STATUSES)
                    ->whereDate('check_out', '<=', $today)),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Booking::STATUS_CANCELLED)),
        ];
    }
}
