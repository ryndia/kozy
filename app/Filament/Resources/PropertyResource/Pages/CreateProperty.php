<?php

namespace App\Filament\Resources\PropertyResource\Pages;

use App\Filament\Resources\PropertyResource;
use App\Models\Property;
use Filament\Resources\Pages\CreateRecord;

class CreateProperty extends CreateRecord
{
    protected static string $resource = PropertyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (array_key_exists('images', $data)) {
            $data['bedrooms'] = Property::deriveBedroomCountFromImages($data['images']);
            $data['kitchens'] = Property::deriveKitchenCountFromImages($data['images']);
            $data['bathrooms'] = Property::deriveBathroomCountFromImages($data['images']);
        }

        return $data;
    }
}
