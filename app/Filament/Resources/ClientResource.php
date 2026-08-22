<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ClientResource\Pages;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class ClientResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Clients';

    protected static string|\UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'client';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contact details')
                ->columnSpanFull()
                ->components([
                    Grid::make(2)->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(255)
                            ->placeholder('+230 5xxx xxxx'),
                        Select::make('role')
                            ->options(static::roleOptions())
                            ->default(UserRole::USER->value)
                            ->native(false)
                            ->required()
                            ->helperText('Renters and administrators can sign in to this panel.'),
                    ]),
                ]),
            Section::make('Access')
                ->columnSpanFull()
                ->components([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->rule(Password::default())
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): string => $operation === 'create'
                            ? 'The client can change this later from their account.'
                            : 'Leave blank to keep the current password.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('bookings')
                ->withSum(['payments as amount_paid_sum' => fn (Builder $payments) => $payments->where('payments.status', 'paid')], 'amount'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Client')
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->sortable()
                    ->weight(FontWeight::Medium),
                TextColumn::make('phone')
                    ->placeholder('No phone')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::roleOptions()[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        UserRole::ADMIN->value => 'danger',
                        UserRole::RENTER->value => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('bookings_count')
                    ->label('Reservations')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('amount_paid_sum')
                    ->label('Total paid')
                    ->money('MUR')
                    ->default(0)
                    ->sortable(),
                TextColumn::make('otp_verified_at')
                    ->label('Verified')
                    ->dateTime('d M Y')
                    ->placeholder('Not verified')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(static::roleOptions())
                    ->multiple()
                    ->default([UserRole::USER->value, UserRole::RENTER->value]),
                TernaryFilter::make('otp_verified_at')
                    ->label('Verification')
                    ->placeholder('Everyone')
                    ->trueLabel('Verified only')
                    ->falseLabel('Unverified only')
                    ->nullable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        // Deleting the account you are signed in with would lock
                        // you out of the panel mid-session.
                        ->visible(fn (User $record): bool => $record->getKey() !== Auth::id()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No clients yet')
            ->emptyStateDescription('Everyone who signs up on the website appears here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var User $record */
        return [
            'Email' => $record->email,
            'Role' => static::roleOptions()[$record->role] ?? $record->role,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            UserRole::USER->value => 'Client',
            UserRole::RENTER->value => 'Renter',
            UserRole::ADMIN->value => 'Administrator',
        ];
    }
}
