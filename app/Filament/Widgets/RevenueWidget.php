<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class RevenueWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $month = [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()];
        $lastMonth = [
            Carbon::now()->subMonthNoOverflow()->startOfMonth(),
            Carbon::now()->subMonthNoOverflow()->endOfMonth(),
        ];

        $revenue = $this->paidBetween($month);
        $previousRevenue = $this->paidBetween($lastMonth);
        $outstanding = (float) Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->sum('amount');

        return [
            Stat::make('Revenue this month', $this->money($revenue))
                ->description($this->trend($revenue, $previousRevenue))
                ->descriptionIcon($revenue >= $previousRevenue ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($revenue >= $previousRevenue ? 'success' : 'danger'),

            Stat::make('Awaiting payment', $this->money($outstanding))
                ->description('Reservations not settled yet')
                ->descriptionIcon('heroicon-m-clock')
                ->color($outstanding > 0 ? 'warning' : 'gray'),

            Stat::make('Average booking value', $this->money($this->averageBookingValue()))
                ->description('Across reservations that are not cancelled')
                ->descriptionIcon('heroicon-m-calculator')
                ->color('info'),
        ];
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $period
     */
    protected function paidBetween(array $period): float
    {
        return (float) Payment::query()
            ->where('status', Payment::STATUS_PAID)
            ->whereBetween('paid_at', $period)
            ->sum('amount');
    }

    protected function averageBookingValue(): float
    {
        return (float) Booking::query()->active()->avg('total_price');
    }

    protected function trend(float $current, float $previous): string
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? 'First revenue of the period' : 'No revenue yet';
        }

        $change = round((($current - $previous) / $previous) * 100);

        return sprintf('%s%d%% vs last month', $change >= 0 ? '+' : '', $change);
    }

    /**
     * Rentals are priced per property, so the panel reports in the currency most
     * of the catalogue uses rather than assuming dollars.
     */
    protected function money(float $amount): string
    {
        return $this->currency().' '.number_format($amount, 2);
    }

    protected function currency(): string
    {
        return Property::query()
            ->whereNotNull('currency')
            ->selectRaw('currency, COUNT(*) as total')
            ->groupBy('currency')
            ->orderByDesc('total')
            ->value('currency') ?? 'MUR';
    }
}
