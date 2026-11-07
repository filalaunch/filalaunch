<?php

namespace App\Filament\Resources\Permissions\Tables;

use App\Models\Permission;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->defaultGroup('group')
            ->groups([
                Group::make('group')
                    ->label('Resource')
                    ->getKeyFromRecordUsing(fn (Permission $record): string => $record->getGroup())
                    ->getTitleFromRecordUsing(fn (Permission $record): string => Str::headline($record->getGroup()))
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('name', $direction))
                    ->collapsible(),
            ])
            ->columns([
                TextColumn::make('name')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('users_count')
                    ->label('Direct users')
                    ->counts('users')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label('Resource')
                    ->options(fn (): array => Permission::query()->pluck('name')
                        ->map(fn (string $name): string => Str::contains($name, '.') ? Str::before($name, '.') : 'other')
                        ->unique()->sort()
                        ->mapWithKeys(fn (string $group): array => [$group => Str::headline($group)])
                        ->all())
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'other' => $query->where('name', 'not like', '%.%'),
                        default => $query->where('name', 'like', $data['value'].'.%'),
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
