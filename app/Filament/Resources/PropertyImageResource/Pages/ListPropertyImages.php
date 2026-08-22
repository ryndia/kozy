<?php

namespace App\Filament\Resources\PropertyImageResource\Pages;

use App\Filament\Resources\PropertyImageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPropertyImages extends ListRecords
{
    protected static string $resource = PropertyImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New image'),
        ];
    }
}
