<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Models\Payment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New payment'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'pending' => Tab::make('Awaiting payment')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Payment::STATUS_PENDING))
                ->badge(Payment::query()->where('status', Payment::STATUS_PENDING)->count())
                ->badgeColor('warning'),

            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Payment::STATUS_PAID)),

            'failed' => Tab::make('Failed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Payment::STATUS_FAILED))
                ->badge(Payment::query()->where('status', Payment::STATUS_FAILED)->count())
                ->badgeColor('danger'),

            'refunded' => Tab::make('Refunded')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Payment::STATUS_REFUNDED)),
        ];
    }
}
