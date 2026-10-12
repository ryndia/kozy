<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every stay on one month grid, so a gap or a double-booked week is visible
 * without reading the reservations table row by row.
 */
class ReservationCalendarWidget extends CalendarWidget
{
    protected bool $eventClickEnabled = true;

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    /**
     * Only the stays overlapping the visible range.
     *
     * The comparison is inclusive on both ends because a stay that starts
     * before the month and ends inside it still occupies nights on screen.
     *
     * @return Collection<int, Booking>|array<int, Booking>|Builder<Booking>
     */
    protected function getEvents(FetchInfo $info): Collection|array|Builder
    {
        return Booking::query()
            ->with(['property', 'client'])
            ->whereDate('check_out', '>=', $info->start)
            ->whereDate('check_in', '<=', $info->end);
    }
}
