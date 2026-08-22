<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Review;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class OccupancyWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $today = Carbon::today();

        $bookingsThisMonth = Booking::query()
            ->active()
            ->whereBetween('check_in', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])
            ->count();

        $staying = Booking::query()
            ->active()
            ->whereDate('check_in', '<=', $today)
            ->whereDate('check_out', '>', $today)
            ->count();

        $activeProperties = Property::query()->where('status', 'active')->count();
        $pendingReviews = Review::query()->where('status', 'pending')->count();

        return [
            Stat::make('Bookings this month', (string) $bookingsThisMonth)
                ->description('Stays starting this month')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),

            Stat::make('Guests in house', (string) $staying)
                ->description($activeProperties > 0
                    ? sprintf('%d of %d active rentals occupied', $staying, $activeProperties)
                    : 'No active rentals yet')
                ->descriptionIcon('heroicon-m-home-modern')
                ->color($staying > 0 ? 'success' : 'gray'),

            Stat::make('Reviews to moderate', (string) $pendingReviews)
                ->description('Waiting for approval')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color($pendingReviews > 0 ? 'warning' : 'gray'),
        ];
    }
}
