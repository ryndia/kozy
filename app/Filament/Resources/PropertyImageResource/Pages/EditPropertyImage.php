<?php

namespace App\Filament\Resources\PropertyImageResource\Pages;

use App\Filament\Resources\PropertyImageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPropertyImage extends EditRecord
{
    protected static string $resource = PropertyImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
