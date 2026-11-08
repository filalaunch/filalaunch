<?php

namespace App\Filament\App\Tenancy;

use App\Support\Teams\Teams;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RegisterTeam extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Create '.Str::lower(Teams::label());
    }

    /**
     * Every signed-in user may create teams (no staff permission needed).
     */
    public static function canView(): bool
    {
        return Teams::enabled() && auth()->check();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(Teams::label().' name')
                    ->required()
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')
                    ->label('URL')
                    ->prefix(url(config('filalaunch.panels.app.path', 'app')).'/')
                    ->required()
                    ->alphaDash()
                    ->maxLength(100)
                    ->unique('teams', 'slug'),
            ]);
    }

    protected function handleRegistration(array $data): Model
    {
        return app(Teams::class)->create(auth()->user(), $data['name'], $data['slug']);
    }
}
