<?php

namespace App\Filament\Resources\PropertyResource\RelationManagers;

use App\Models\PropertyImage;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->components([
                Select::make('room_type')
                    ->label('Room type')
                    ->options(PropertyImage::roomTypeOptions())
                    ->default('other')
                    ->native(false)
                    ->required(),
                TextInput::make('room_name')
                    ->label('Room name')
                    ->maxLength(255),
            ]),
            FileUpload::make('image_path')
                ->label('Photo')
                ->image()
                ->imageEditor()
                ->disk('public')
                ->directory('properties')
                ->required()
                ->columnSpanFull(),
            Grid::make(3)->components([
                TextInput::make('alt_text')
                    ->label('Guest-facing caption')
                    ->maxLength(255),
                TextInput::make('order')
                    ->numeric()
                    ->default(0),
                Toggle::make('is_cover')
                    ->label('Use as cover image'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('order')
            ->columns([
                ImageColumn::make('thumbnail_path')
                    ->label('Photo')
                    ->disk('public')
                    ->square(),
                TextColumn::make('room_name')
                    ->label('Room')
                    ->placeholder('Unnamed')
                    ->description(fn (PropertyImage $record): string => PropertyImage::roomTypeOptions()[$record->room_type] ?? 'Other room'),
                TextColumn::make('alt_text')
                    ->label('Caption')
                    ->placeholder('No caption')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('order')
                    ->sortable(),
                IconColumn::make('is_cover')
                    ->label('Cover')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add room photo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Gallery';
    }
}
