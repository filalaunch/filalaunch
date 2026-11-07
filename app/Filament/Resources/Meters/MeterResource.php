<?php

namespace App\Filament\Resources\Meters;

use App\Enums\MeterReset;
use App\Filament\Resources\Meters\Pages\ManageMeters;
use App\Models\Meter;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MeterResource extends Resource
{
    protected static ?string $model = Meter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Usage meters';

    protected static ?string $modelLabel = 'usage meter';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('API calls'),
                TextInput::make('key')
                    ->required()
                    ->alphaDash()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->placeholder('api_calls')
                    ->helperText('Used in code: $user->recordUsage(\'api_calls\'). Don\'t change it once in use.'),
                TextInput::make('unit')
                    ->required()
                    ->default('unit')
                    ->placeholder('call'),
                Select::make('reset')
                    ->label('Usage resets')
                    ->options(MeterReset::class)
                    ->default(MeterReset::Monthly->value)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('planMeters'))
            ->columns([
                TextColumn::make('name')->weight('medium')->searchable(),
                TextColumn::make('key')->fontFamily('mono')->copyable(),
                TextColumn::make('unit'),
                TextColumn::make('reset')->label('Resets'),
                TextColumn::make('plan_meters_count')->label('Plans'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Meter $record): bool => $record->plan_meters_count === 0),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMeters::route('/'),
        ];
    }
}
