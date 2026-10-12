<?php

namespace App\Filament\Resources\RefundResource\Pages;

use App\Filament\Resources\RefundResource;
use App\Models\Refund;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'outstanding' => Tab::make('Awaiting payout')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Refund::STATUS_PENDING)),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Refund::STATUS_COMPLETED)),
            'failed' => Tab::make('Failed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Refund::STATUS_FAILED)),
            'all' => Tab::make('All'),
        ];
    }
}
