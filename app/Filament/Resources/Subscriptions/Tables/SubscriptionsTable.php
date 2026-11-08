<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\Actions\SubscriptionActions;
use App\Models\Subscription;
use App\Support\Teams\Teams;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'plan', 'team']))
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->description(fn (Subscription $record): string => (string) $record->user?->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('team.name')
                    ->label(Teams::label())
                    ->placeholder('Personal')
                    ->visible(Teams::enabled())
                    ->searchable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->description(fn (Subscription $record): string => (string) $record->plan?->priceLabel()),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Subscription $record): SubscriptionStatus => $record->displayStatus())
                    ->description(fn (Subscription $record): ?string => $record->inGracePeriod() ? 'In grace period' : null),
                TextColumn::make('starts_at')
                    ->label('Started')
                    ->date()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->date()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('trial_ends_at')
                    ->label('Trial ends')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('plan')
                    ->relationship('plan', 'name')
                    ->preload(),
                SelectFilter::make('status')
                    ->options(SubscriptionStatus::class),
                SelectFilter::make('team')
                    ->label(Teams::label())
                    ->relationship('team', 'name')
                    ->searchable()
                    ->visible(Teams::enabled()),
                TernaryFilter::make('active')
                    ->label('Grants access now')
                    ->queries(
                        true: fn (Builder $query) => $query->active(),
                        false: fn (Builder $query) => $query->whereNotIn('id', Subscription::query()->active()->select('id')),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(SubscriptionActions::all()),
            ]);
    }
}
