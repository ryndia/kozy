<?php

namespace App\Filament\Resources;

use App\Filament\Forms\RelationshipSelects;
use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Revenue';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reservation')
                ->columnSpanFull()
                ->description('The stay this payment settles.')
                ->components([
                    RelationshipSelects::booking()
                        ->required(),
                ]),
            Section::make('Payment')
                ->columnSpanFull()
                ->components([
                    Grid::make(2)->components([
                        TextInput::make('amount')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Select::make('status')
                            ->options(static::statusOptions())
                            ->default(Payment::STATUS_PENDING)
                            ->native(false)
                            ->required()
                            ->live(),
                        Select::make('method')
                            ->options([
                                'card' => 'Card',
                                'stripe' => 'Stripe',
                                'cash' => 'Cash',
                                'bank_transfer' => 'Bank transfer',
                            ])
                            ->native(false)
                            ->placeholder('Not specified'),
                        TextInput::make('transaction_ref')
                            ->label('Transaction reference')
                            ->maxLength(255)
                            ->hint('The reference from the payment provider, if any.'),
                        DateTimePicker::make('paid_at')
                            ->label('Paid at')
                            ->native(false)
                            ->seconds(false)
                            ->visible(fn ($get): bool => $get('status') === Payment::STATUS_PAID),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['booking.property', 'booking.client']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Payment')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereKey((int) preg_replace('/\D/', '', $search)))
                    ->sortable(['id']),
                TextColumn::make('booking.property.title')
                    ->label('Property')
                    ->description(fn (Payment $record): ?string => $record->booking?->reference)
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('booking.client.name')
                    ->label('Guest')
                    ->description(fn (Payment $record): ?string => $record->booking?->client?->email)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn (Payment $record): string => $record->currency)
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('method')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Not specified')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? str($state)->replace('_', ' ')->title()->toString() : null)
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusOptions()[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        Payment::STATUS_PAID => 'success',
                        Payment::STATUS_PENDING => 'warning',
                        Payment::STATUS_FAILED => 'danger',
                        Payment::STATUS_REFUNDED => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('transaction_ref')
                    ->label('Transaction')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')
                    ->label('Paid at')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Unpaid')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(static::statusOptions())
                    ->multiple(),
                SelectFilter::make('method')
                    ->options([
                        'card' => 'Card',
                        'stripe' => 'Stripe',
                        'cash' => 'Cash',
                        'bank_transfer' => 'Bank transfer',
                    ]),
                Filter::make('paid')
                    ->label('Settled payments only')
                    ->query(fn (Builder $query): Builder => $query->where('status', Payment::STATUS_PAID))
                    ->toggle(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('markAsPaid')
                        ->label('Mark as paid')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Payment $record): bool => $record->status !== Payment::STATUS_PAID)
                        ->action(fn (Payment $record) => $record->forceFill([
                            'status' => Payment::STATUS_PAID,
                            'paid_at' => $record->paid_at ?? now(),
                        ])->save()),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No payments recorded');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['transaction_ref', 'booking.property.title', 'booking.client.name'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Payment $record */
        return [
            'Guest' => $record->booking->client->name,
            'Amount' => $record->currency.' '.number_format((float) $record->amount, 2),
            'Status' => static::statusOptions()[$record->status] ?? $record->status,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            Payment::STATUS_PENDING => 'Pending',
            Payment::STATUS_PAID => 'Paid',
            Payment::STATUS_FAILED => 'Failed',
            Payment::STATUS_REFUNDED => 'Refunded',
        ];
    }
}
