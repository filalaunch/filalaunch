<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Enums\PlanType;
use App\Models\Plan;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['subscriptions as active_subscriptions_count' => fn ($q) => $q->active()]))
            ->columns([
                TextColumn::make('name')
                    ->weight('medium')
                    ->description(fn (Plan $record): string => $record->slug)
                    ->searchable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('price')
                    ->label('Price')
                    ->state(fn (Plan $record): string => $record->priceLabel()),
                TextColumn::make('active_subscriptions_count')
                    ->label('Active subscribers')
                    ->numeric(),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(PlanType::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
