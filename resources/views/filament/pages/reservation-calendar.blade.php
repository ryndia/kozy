<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-4 text-sm">
        @foreach ([
            'Pending' => \App\Models\Booking::CALENDAR_COLOURS[\App\Models\Booking::STATUS_PENDING],
            'Confirmed' => \App\Models\Booking::CALENDAR_COLOURS[\App\Models\Booking::STATUS_CONFIRMED],
            'Completed' => \App\Models\Booking::CALENDAR_COLOURS[\App\Models\Booking::STATUS_COMPLETED],
            'Cancelled' => \App\Models\Booking::CALENDAR_COLOURS[\App\Models\Booking::STATUS_CANCELLED],
        ] as $label => $colour)
            <span class="flex items-center gap-2">
                <span class="size-3 rounded-full" style="background-color: {{ $colour }}"></span>
                <span class="text-gray-600 dark:text-gray-400">{{ $label }}</span>
            </span>
        @endforeach
    </div>

    <x-filament-widgets::widgets
        :columns="$this->getColumns()"
        :widgets="$this->getWidgets()"
    />
</x-filament-panels::page>
