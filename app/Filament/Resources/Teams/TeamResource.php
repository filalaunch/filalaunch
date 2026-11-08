<?php

namespace App\Filament\Resources\Teams;

use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Tables\TeamsTable;
use App\Models\Team;
use App\Support\Teams\Teams;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Staff overview of customer teams. Teams are created and managed by customers
 * in the /app panel; staff can look inside and delete. Hidden while teams are off.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return Teams::enabled() && parent::canAccess();
    }

    public static function canCreate(): bool
    {
        return false; // customers create teams in the customer panel
    }

    public static function getModelLabel(): string
    {
        return Teams::label();
    }

    public static function getPluralModelLabel(): string
    {
        return Str::plural(Teams::label());
    }

    public static function table(Table $table): Table
    {
        return TeamsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
        ];
    }
}
