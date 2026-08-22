<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PropertyResource\Pages;
use App\Helpers\PropertyFeatureHelper;
use App\Models\Property;
use App\Models\PropertyImage;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Rental properties';

    protected static string|\UnitEnum|null $navigationGroup = 'Rental management';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'rental property';

    protected static ?string $recordTitleAttribute = 'title';

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Property overview')
                ->columnSpanFull()
                ->description('This information is shown first on the public listing and property detail pages.')
                ->components([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->hint('Use a clear guest-facing title such as “Sea-view villa in Grand Baie”.'),
                    RichEditor::make('description')
                        ->hint('Add the story of the stay, the atmosphere, and what makes the property special.'),
                    Grid::make(2)
                        ->components([
                            TextInput::make('price_per_night')->numeric()->required(),
                            Select::make('currency')
                                ->options([
                                    'USD' => 'USD',
                                    'EUR' => 'EUR',
                                    'GBP' => 'GBP',
                                    'AED' => 'AED',
                                    'MAD' => 'MAD',
                                    'KES' => 'KES',
                                    'MUR' => 'MUR',
                                    'ZAR' => 'ZAR',
                                ])
                                ->default('MUR')
                                ->required(),
                            TextInput::make('max_guests')
                                ->numeric()
                                ->required()
                                ->hint('The maximum number of guests who can stay comfortably.'),
                            Select::make('status')
                                ->options([
                                    'active' => 'Active',
                                    'inactive' => 'Inactive',
                                ])
                                ->default('active')
                                ->required(),
                        ]),
                ]),
            Section::make('Space layout')
                ->columnSpanFull()
                ->description('Guests should immediately understand how many rooms and service spaces the rental includes.')
                ->components([
                    Grid::make(3)
                        ->components([
                            TextInput::make('bedrooms')
                                ->label('Bedrooms')
                                ->numeric()
                                ->default(0)
                                ->disabled()
                                ->hint('Calculated automatically from gallery items marked as bedroom.'),
                            TextInput::make('kitchens')
                                ->label('Kitchens')
                                ->numeric()
                                ->default(0)
                                ->disabled()
                                ->hint('Calculated automatically from gallery items marked as kitchen.'),
                            TextInput::make('bathrooms')
                                ->label('Bathrooms')
                                ->numeric()
                                ->default(0)
                                ->disabled()
                                ->hint('Calculated automatically from gallery items marked as bathroom.'),
                        ]),
                    TagsInput::make('amenities')
                        ->hint('Add short guest-facing tags like WiFi, workspace, washer, mountain view.'),
                    Select::make('features')
                        ->label('Features & amenities')
                        ->multiple()
                        ->searchable()
                        ->options(PropertyFeatureHelper::getFeatureOptions())
                        ->placeholder('Select room features')
                        ->hint('Choose the room features available in this property.'),
                ]),
            Section::make('Arrival & location')
                ->columnSpanFull()
                ->description('These fields power the map, address, and check-in information on the frontend.')
                ->components([
                    TextInput::make('address')
                        ->hint('Add the full address. If no coordinates are saved, the public page will show a no-location placeholder.'),
                    Grid::make(2)
                        ->components([
                            TextInput::make('lat')
                                ->label('Latitude')
                                ->numeric()
                                ->step('any')
                                ->hint('Optional. Leave blank to keep the location placeholder on the public page.'),
                            TextInput::make('lng')
                                ->label('Longitude')
                                ->numeric()
                                ->step('any')
                                ->hint('Optional. Leave blank to keep the location placeholder on the public page.'),
                            TimePicker::make('check_in')
                                ->label('Check-in time')
                                ->native(false)
                                ->hint('Optional. This will be shown on the public page if set.'),
                            TimePicker::make('check_out')
                                ->label('Check-out time')
                                ->native(false)
                                ->hint('Optional. This will be shown on the public page if set.'),
                        ]),
                ]),
            Section::make('Property gallery')
                ->columnSpanFull()
                ->description('Upload a cover image and one photo for each important room so guests can preview the full layout.')
                ->components([
                    Repeater::make('images')
                        ->relationship()
                        ->addActionLabel('Add room photo')
                        ->live()
                        ->afterStateHydrated(function (Set $set, ?array $state): void {
                            $set('bedrooms', Property::deriveBedroomCountFromImages($state));
                            $set('kitchens', Property::deriveKitchenCountFromImages($state));
                            $set('bathrooms', Property::deriveBathroomCountFromImages($state));
                        })
                        ->afterStateUpdated(function (Set $set, ?array $state): void {
                            $set('bedrooms', Property::deriveBedroomCountFromImages($state));
                            $set('kitchens', Property::deriveKitchenCountFromImages($state));
                            $set('bathrooms', Property::deriveBathroomCountFromImages($state));
                        })
                        ->schema([
                            Grid::make(2)
                                ->components([
                                    Select::make('room_type')
                                        ->label('Room type')
                                        ->options(PropertyImage::roomTypeOptions())
                                        ->default('other')
                                        ->required(),
                                    TextInput::make('room_name')
                                        ->label('Room name')
                                        ->maxLength(255)
                                        ->hint('Examples: Master bedroom, Kitchenette, Family bathroom.'),
                                    FileUpload::make('image_path')
                                        ->image()
                                        ->acceptedFileTypes(['image/*'])
                                        ->disk('public')
                                        ->directory('properties')
                                        ->required(),
                                    TextInput::make('alt_text')
                                        ->label('Guest-facing caption')
                                        ->maxLength(255)
                                        ->hint('Short description shown to help guests understand the photo.'),
                                    Toggle::make('is_cover')
                                        ->label('Use as cover image'),
                                    TextInput::make('order')
                                        ->numeric()
                                        ->default(0)
                                        ->hint('Lower numbers appear first in the gallery.'),
                                ]),
                        ])
                        ->itemLabel(fn (array $state): string => $state['room_name'] ?: (PropertyImage::roomTypeOptions()[$state['room_type'] ?? 'other'] ?? 'Room photo')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('images')
                ->withCount('bookings'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('cover_image_path')
                    ->label('Photo')
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(asset('favicon.svg')),
                TextColumn::make('title')
                    ->label('Property')
                    ->description(fn (Property $record): string => $record->address ?: 'No address yet')
                    ->searchable(['title', 'address'])
                    ->sortable()
                    ->weight(FontWeight::Medium)
                    ->wrap(),
                TextColumn::make('layout')
                    ->label('Layout')
                    ->state(fn (Property $record): string => sprintf(
                        '%d bed · %d bath · %d kitchen',
                        (int) $record->bedrooms,
                        (int) $record->bathrooms,
                        (int) $record->kitchens,
                    ))
                    ->toggleable(),
                TextColumn::make('max_guests')
                    ->label('Sleeps')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('price_per_night')
                    ->label('Per night')
                    ->money(fn (Property $record): string => $record->currency ?: 'MUR')
                    ->sortable(),
                TextColumn::make('bookings_count')
                    ->label('Reservations')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('features')
                    ->label('Features')
                    ->formatStateUsing(function ($state) {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }

                        return PropertyFeatureHelper::formatFeaturesWithEmojis($state);
                    })
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
                SelectFilter::make('currency')
                    ->options(fn (): array => Property::query()
                        ->whereNotNull('currency')
                        ->distinct()
                        ->pluck('currency', 'currency')
                        ->all())
                    ->multiple(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No rentals yet')
            ->emptyStateDescription('Add your first property to start taking reservations.');
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'address'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Property $record */
        return [
            'Address' => $record->address ?: '-',
            'Rate' => ($record->currency ?: 'MUR').' '.number_format((float) $record->price_per_night, 2).' per night',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProperties::route('/'),
            'create' => Pages\CreateProperty::route('/create'),
            'edit' => Pages\EditProperty::route('/{record}/edit'),
        ];
    }
}
