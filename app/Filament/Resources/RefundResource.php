<?php

namespace App\Filament\Resources;

use App\Actions\Payments\RefundBookingPayment;
use App\Filament\Resources\RefundResource\Pages;
use App\Models\Refund;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The refund payout queue.
 *
 * A Stripe refund settles itself and lands here already completed. Anything
 * paid another way has no API to call, so it sits as pending until someone
 * makes the transfer and marks it off here.
 */
class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Revenue';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Refund')
                ->columnSpanFull()
                ->components([
                    TextInput::make('amount')->numeric()->disabled(),
                    TextInput::make('fee_amount')->label('Fee retained')->numeric()->disabled(),
                    TextInput::make('provider_reference')->label('Provider reference')->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->label('Refund')->searchable(false),
                TextColumn::make('booking.label')->label('Reservation')->wrap()->limit(60),
                TextColumn::make('amount')
                    ->money(fn (Refund $record): string => $record->currency ?: 'MUR')
                    ->summarize(Sum::make()->label('Total'))
                    ->sortable(),
                TextColumn::make('fee_amount')
                    ->label('Fee kept')
                    ->money(fn (Refund $record): string => $record->currency ?: 'MUR')
                    ->toggleable(),
                TextColumn::make('method')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? strtoupper($state) : '—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Refund::STATUS_COMPLETED => 'success',
                        Refund::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('processed_at')->label('Paid out')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Refund::STATUS_PENDING => 'Awaiting payout',
                    Refund::STATUS_COMPLETED => 'Completed',
                    Refund::STATUS_FAILED => 'Failed',
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    static::markPaidAction(),
                ]),
            ])
            ->emptyStateHeading('No refunds')
            ->emptyStateDescription('Cancellations that owe a guest money show up here.');
    }

    /**
     * Records money returned by hand. Kept deliberately explicit: the amount is
     * already decided, so all this captures is that it actually went out and
     * what reference it went out under.
     */
    protected static function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label('Mark as paid out')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Refund $record): bool => ! $record->isCompleted())
            ->schema([
                TextInput::make('provider_reference')
                    ->label('Transfer reference')
                    ->maxLength(255)
                    ->helperText('The bank or switch reference for the transfer you made.'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Mark this refund as paid out?')
            ->action(function (Refund $record, array $data): void {
                app(RefundBookingPayment::class)->markCompleted(
                    $record,
                    $data['provider_reference'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title('Refund marked as paid')
                    ->send();
            });
    }

    public static function getNavigationBadge(): ?string
    {
        $outstanding = Refund::query()->outstanding()->count();

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRefunds::route('/'),
            'view' => Pages\ViewRefund::route('/{record}'),
        ];
    }
}
