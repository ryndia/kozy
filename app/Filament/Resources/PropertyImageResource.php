<?php

namespace App\Filament\Resources;

use App\Filament\Forms\RelationshipSelects;
use App\Filament\Resources\PropertyImageResource\Pages;
use App\Models\PropertyImage;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PropertyImageResource extends Resource
{
    protected static ?string $model = PropertyImage::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $modelLabel = 'room photo';

    protected static ?string $recordTitleAttribute = 'display_name';

    /**
     * Photos are managed from the property form; this resource stays reachable
     * by URL for one-off edits but is kept out of the sidebar.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Room photo')
                ->columnSpanFull()
                ->components([
                    RelationshipSelects::property()->required(),
                    Grid::make(2)->components([
                        Select::make('room_type')
                            ->label('Room type')
                            ->options(PropertyImage::roomTypeOptions())
                            ->default('other')
                            ->native(false)
                            ->required(),
                        TextInput::make('room_name')
                            ->label('Room name')
                            ->maxLength(255)
                            ->hint('Examples: Master bedroom, Kitchenette, Family bathroom.'),
                    ]),
                    FileUpload::make('image_path')
                        ->label('Photo')
                        ->directory('properties')
                        ->disk('public')
                        ->image()
                        ->imageEditor()
                        ->required()
                        ->columnSpanFull(),
                    Grid::make(3)->components([
                        TextInput::make('alt_text')
                            ->label('Guest-facing caption')
                            ->maxLength(255),
                        TextInput::make('order')
                            ->numeric()
                            ->default(0)
                            ->hint('Lower numbers appear first.'),
                        Toggle::make('is_cover')
                            ->label('Use as cover image'),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('property'))
            ->defaultSort('order')
            ->columns([
                ImageColumn::make('thumbnail_path')
                    ->label('Photo')
                    ->disk('public')
                    ->square(),
                TextColumn::make('property.title')
                    ->label('Property')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('room_name')
                    ->label('Room')
                    ->placeholder('Unnamed')
                    ->description(fn (PropertyImage $record): string => PropertyImage::roomTypeOptions()[$record->room_type] ?? 'Other room')
                    ->searchable(),
                TextColumn::make('room_type')
                    ->label('Room type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => PropertyImage::roomTypeOptions()[$state] ?? 'Other room')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('alt_text')
                    ->label('Caption')
                    ->placeholder('No caption')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('order')
                    ->label('Order')
                    ->sortable(),
                IconColumn::make('is_cover')
                    ->label('Cover')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('property_id')
                    ->label('Property')
                    ->relationship('property', 'title')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('room_type')
                    ->label('Room type')
                    ->options(PropertyImage::roomTypeOptions())
                    ->multiple(),
                TernaryFilter::make('is_cover')
                    ->label('Cover image'),
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
            ->emptyStateHeading('No photos yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPropertyImages::route('/'),
            'create' => Pages\CreatePropertyImage::route('/create'),
            'edit' => Pages\EditPropertyImage::route('/{record}/edit'),
        ];
    }
}
