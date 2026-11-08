<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\ResetTwoFactorAction;
use App\Models\User;
use App\Support\Teams\Teams;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(Teams::enabled() ? ['roles', 'teams'] : ['roles']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        config('filalaunch.roles.super_admin') => 'danger',
                        config('filalaunch.roles.admin') => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('teams.name')
                    ->label(fn (): string => Str::plural(Teams::label()))
                    ->badge()
                    ->color('info')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->visible(fn (): bool => Teams::enabled()),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->state(fn (User $record): bool => $record->hasVerifiedEmail())
                    ->boolean(),

                IconColumn::make('app_authentication_secret')
                    ->label('2FA')
                    ->state(fn (User $record): bool => $record->hasAppAuthenticationEnabled())
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),

                TernaryFilter::make('email_verified_at')
                    ->label('Email verified')
                    ->nullable(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    ResetTwoFactorAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
