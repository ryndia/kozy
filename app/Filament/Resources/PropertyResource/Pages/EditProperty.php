<?php

namespace App\Filament\Resources\PropertyResource\Pages;

use App\Filament\Resources\PropertyResource;
use App\Models\Property;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProperty extends EditRecord
{
    protected static string $resource = PropertyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('images', $data)) {
            $data['bedrooms'] = Property::deriveBedroomCountFromImages($data['images']);
            $data['kitchens'] = Property::deriveKitchenCountFromImages($data['images']);
            $data['bathrooms'] = Property::deriveBathroomCountFromImages($data['images']);
        }

        return $data;
    }
}
