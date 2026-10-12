<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ReservationCalendarWidget;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * The reservations month view, alongside the reservations table.
 */
class ReservationCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Rental management';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Reservation calendar';

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $slug = 'reservation-calendar';

    protected string $view = 'filament.pages.reservation-calendar';

    /**
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        return [
            ReservationCalendarWidget::class,
        ];
    }

    /**
     * @return int|string|array<string, int|null>
     */
    public function getColumns(): int|string|array
    {
        return 1;
    }
}
