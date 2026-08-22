<?php

namespace App\Filament\Resources;

use App\Filament\Forms\RelationshipSelects;
use App\Filament\Resources\ReviewResource\Pages;
use App\Models\Review;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static string|\UnitEnum|null $navigationGroup = 'Rental management';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'headline';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Who wrote it')
                ->columnSpanFull()
                ->components([
                    Grid::make(3)->components([
                        RelationshipSelects::property()
                            ->required(),
                        RelationshipSelects::client()
                            ->required(),
                        RelationshipSelects::booking()
                            ->placeholder('Not linked to a stay'),
                    ]),
                ]),
            Section::make('The review')
                ->columnSpanFull()
                ->components([
                    Grid::make(2)->components([
                        Select::make('rating')
                            ->options(static::ratingOptions())
                            ->native(false)
                            ->required(),
                        Select::make('status')
                            ->options(static::statusOptions())
                            ->default('pending')
                            ->native(false)
                            ->required()
                            ->helperText('Only approved reviews are shown on the public site.'),
                    ]),
                    Textarea::make('comment')
                        ->rows(5)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['property', 'client']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('property.title')
                    ->label('Property')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('client.name')
                    ->label('Guest')
                    ->description(fn (Review $record): ?string => $record->client?->email)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rating')
                    ->label('Rating')
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', max(5 - $state, 0)))
                    ->color(fn (int $state): string => $state >= 4 ? 'success' : ($state >= 3 ? 'warning' : 'danger'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('comment')
                    ->label('Comment')
                    ->placeholder('No comment')
                    ->limit(70)
                    ->tooltip(fn (Review $record): ?string => $record->comment)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusOptions()[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'hidden' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(static::statusOptions())
                    ->multiple(),
                SelectFilter::make('rating')
                    ->options(static::ratingOptions())
                    ->multiple(),
                SelectFilter::make('property_id')
                    ->label('Property')
                    ->relationship('property', 'title')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Review $record): bool => $record->status !== 'approved')
                    ->action(fn (Review $record) => $record->update(['status' => 'approved'])),
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('hide')
                        ->label('Hide from site')
                        ->icon('heroicon-o-eye-slash')
                        ->color('gray')
                        ->visible(fn (Review $record): bool => $record->status !== 'hidden')
                        ->action(fn (Review $record) => $record->update(['status' => 'hidden'])),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No reviews yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviews::route('/'),
            'create' => Pages\CreateReview::route('/create'),
            'edit' => Pages\EditReview::route('/{record}/edit'),
        ];
    }

    /**
     * Reviews waiting for moderation.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['comment', 'property.title', 'client.name'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Review $record */
        return [
            'Guest' => $record->client->name,
            'Rating' => $record->rating.'/5',
            'Status' => static::statusOptions()[$record->status] ?? $record->status,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'pending' => 'Pending',
            'approved' => 'Approved',
            'hidden' => 'Hidden',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function ratingOptions(): array
    {
        return [
            1 => '1 - Poor',
            2 => '2 - Fair',
            3 => '3 - Good',
            4 => '4 - Very good',
            5 => '5 - Excellent',
        ];
    }
}
