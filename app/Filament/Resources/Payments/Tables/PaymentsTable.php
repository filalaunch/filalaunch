<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Billing\Billing;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Pending first, newest first.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'plan', 'reviewer'])
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->latest())
            ->columns([
                TextColumn::make('reference')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->description(fn (Payment $record): string => (string) $record->user?->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->description(fn (Payment $record): string => $record->kind->getLabel()),
                TextColumn::make('amount')
                    ->state(fn (Payment $record): string => $record->formattedAmount()),
                TextColumn::make('transaction_id')
                    ->label('Bank ref.')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (Payment $record): ?string => $record->review_note),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentStatus::class),
                SelectFilter::make('kind')
                    ->options(PaymentKind::class),
                SelectFilter::make('plan')
                    ->relationship('plan', 'name'),
            ])
            ->recordActions([
                Action::make('details')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (Payment $record): string => "Payment {$record->reference}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('user.email')->label('User'),
                        TextEntry::make('plan.name')->label('Plan'),
                        TextEntry::make('kind')->badge(),
                        TextEntry::make('amount')->state(fn (Payment $record): string => $record->formattedAmount()),
                        TextEntry::make('method')->formatStateUsing(fn (string $state): string => str($state)->headline()),
                        TextEntry::make('transaction_id')->label('Bank transaction reference')->copyable(),
                        TextEntry::make('note')->label('Customer note')->placeholder('—'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('review_note')->label('Review note')->placeholder('—'),
                        TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                        TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                    ])
                    ->extraModalFooterActions(fn (Payment $record): array => [
                        Action::make('receipt')
                            ->label('Open receipt')
                            ->icon(Heroicon::OutlinedPaperClip)
                            ->url(route('billing.receipt', $record), shouldOpenInNewTab: true)
                            ->visible(filled($record->proof_path)),
                    ]),
                Action::make('receipt')
                    ->label('Receipt')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->color('gray')
                    ->url(fn (Payment $record): string => route('billing.receipt', $record), shouldOpenInNewTab: true)
                    ->visible(fn (Payment $record): bool => filled($record->proof_path)),
                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Payment $record): string => "Confirm you received {$record->formattedAmount()} (bank ref. {$record->transaction_id}). The subscription becomes active.")
                    ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Pending && auth()->user()->can('update', $record))
                    ->action(function (Payment $record): void {
                        try {
                            app(Billing::class)->approve($record, auth()->user());
                            Notification::make()->success()->title('Payment approved — subscription active')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Pending && auth()->user()->can('update', $record))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason (shown to the customer)')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        try {
                            app(Billing::class)->reject($record, $data['reason'], auth()->user());
                            Notification::make()->success()->title('Payment rejected')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
