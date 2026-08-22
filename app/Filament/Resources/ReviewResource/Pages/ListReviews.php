<?php

namespace App\Filament\Resources\ReviewResource\Pages;

use App\Filament\Resources\ReviewResource;
use App\Models\Review;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New review'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Awaiting moderation')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'pending'))
                ->badge(Review::query()->where('status', 'pending')->count())
                ->badgeColor('warning'),

            'approved' => Tab::make('Published')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'approved')),

            'hidden' => Tab::make('Hidden')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'hidden')),

            'all' => Tab::make('All'),
        ];
    }
}
