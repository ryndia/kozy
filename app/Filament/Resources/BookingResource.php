<?php

namespace App\Filament\Resources;

use App\Actions\Bookings\CancelBookingAction;
use App\Filament\Forms\RelationshipSelects;
use App\Filament\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Support\CancellationPolicy;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Reservations';

    protected static string|\UnitEnum|null $navigationGroup = 'Rental management';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'reservation';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stay')
                ->columnSpanFull()
                ->description('Who is staying where, and for how long.')
                ->components([
                    Grid::make(2)->components([
                        RelationshipSelects::property()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get) => $set('total_price', static::estimateTotal($get))),
                        RelationshipSelects::client()
                            ->required(),
                    ]),
                    Grid::make(3)->components([
                        DatePicker::make('check_in')
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get) => $set('total_price', static::estimateTotal($get))),
                        DatePicker::make('check_out')
                            ->native(false)
                            ->required()
                            ->after('check_in')
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get) => $set('total_price', static::estimateTotal($get))),
                        TextInput::make('guests')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ]),
                ]),
            Section::make('Booking status')
                ->columnSpanFull()
                ->components([
                    Grid::make(2)->components([
                        Select::make('status')
                            ->options(static::statusOptions())
                            ->default(Booking::STATUS_PENDING)
                            ->native(false)
                            ->required(),
                        TextInput::make('total_price')
                            ->label('Total price')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix(fn (Get $get): string => static::propertyFrom($get)?->currency ?: 'MUR')
                            ->hint('Suggested from the nightly rate; override it for a custom quote.'),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['property', 'client', 'payment']))
            ->defaultSort('check_in', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereKey((int) preg_replace('/\D/', '', $search)))
                    ->sortable(['id'])
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('property.title')
                    ->label('Property')
                    ->description(fn (Booking $record): ?string => $record->property?->address)
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('client.name')
                    ->label('Guest')
                    ->description(fn (Booking $record): ?string => $record->client?->email)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('check_in')
                    ->label('Check in')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('check_out')
                    ->label('Check out')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('nights')
                    ->label('Nights')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('guests')
                    ->label('Guests')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_price')
                    ->label('Total')
                    ->money(fn (Booking $record): string => $record->currency)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusOptions()[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        Booking::STATUS_CONFIRMED => 'success',
                        Booking::STATUS_PENDING => 'warning',
                        Booking::STATUS_CANCELLED => 'danger',
                        Booking::STATUS_COMPLETED => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('payment.status')
                    ->label('Payment')
                    ->badge()
                    ->placeholder('Not recorded')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? ucfirst($state) : null)
                    ->color(fn (?string $state): string => match ($state) {
                        Payment::STATUS_PAID => 'success',
                        Payment::STATUS_PENDING => 'warning',
                        Payment::STATUS_FAILED => 'danger',
                        Payment::STATUS_REFUNDED => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Booked on')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(static::statusOptions())
                    ->multiple(),
                SelectFilter::make('property_id')
                    ->label('Property')
                    ->relationship('property', 'title')
                    ->searchable()
                    ->preload(),
                Filter::make('stay')
                    ->schema([
                        DatePicker::make('from')->label('Staying from')->native(false),
                        DatePicker::make('until')->label('Staying until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('check_out', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('check_in', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Staying from '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Staying until '.$data['until'];
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    static::cancelAction(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No reservations yet')
            ->emptyStateDescription('Reservations made on the website show up here, and you can add one manually.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }

    /**
     * Pending reservations need an admin decision, so surface the count.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->where('status', Booking::STATUS_PENDING)->count();

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
        return ['property.title', 'client.name', 'client.email'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Booking $record */
        return [
            'Guest' => $record->client->name,
            'Stay' => $record->check_in->format('d M Y').' → '.$record->check_out->format('d M Y'),
            'Status' => static::statusOptions()[$record->status] ?? $record->status,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            Booking::STATUS_PENDING => 'Pending',
            Booking::STATUS_CONFIRMED => 'Confirmed',
            Booking::STATUS_COMPLETED => 'Completed',
            Booking::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * Nightly rate times the number of nights, as a starting point for the total.
     */
    protected static function estimateTotal(Get $get): float
    {
        $property = static::propertyFrom($get);
        $checkIn = $get('check_in');
        $checkOut = $get('check_out');

        if (! $property || ! $checkIn || ! $checkOut) {
            return 0.0;
        }

        $nights = (int) Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut));

        return round((float) $property->price_per_night * max($nights, 0), 2);
    }

    /**
     * The property currently selected in the form, if any.
     */
    protected static function propertyFrom(Get $get): ?Property
    {
        return Property::query()->whereKey($get('property_id'))->first();
    }

    /**
     * Cancel a stay on a guest's behalf.
     *
     * Unlike the guest's own cancel button this works on a stay that has
     * already started, which is exactly why it insists on a reason. The refund
     * is prefilled from the property's policy - nothing, once check-in has
     * passed - and can be overridden when the circumstances warrant it.
     */
    protected static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel reservation')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Booking $record): bool => $record->status !== Booking::STATUS_CANCELLED)
            ->schema([
                Placeholder::make('quote')
                    ->label('Policy refund')
                    ->content(function (Booking $record): string {
                        $quote = CancellationPolicy::quote($record);

                        return sprintf(
                            '%s %s of %s %s paid. %s',
                            $quote->currency,
                            number_format($quote->refund, 2),
                            $quote->currency,
                            number_format($quote->paid, 2),
                            $quote->explanation,
                        );
                    }),
                TextInput::make('refund_amount')
                    ->label('Refund to issue')
                    ->numeric()
                    ->minValue(0)
                    ->prefix(fn (Booking $record): string => $record->currency)
                    ->default(fn (Booking $record): float => CancellationPolicy::quote($record)->refund)
                    ->helperText('Capped at what the guest actually paid. Leave as-is to follow the policy.'),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(500)
                    ->helperText('Shown to the guest in their cancellation email.'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Cancel this reservation?')
            ->modalSubmitActionLabel('Cancel reservation')
            ->action(function (Booking $record, array $data): void {
                app(CancelBookingAction::class)->forAdmin(
                    $record,
                    auth()->user(),
                    $data['reason'] ?? null,
                    isset($data['refund_amount']) ? (float) $data['refund_amount'] : null,
                );

                Notification::make()
                    ->success()
                    ->title('Reservation cancelled')
                    ->body('The guest has been emailed. Any refund is in the payout queue.')
                    ->send();
            });
    }
}
