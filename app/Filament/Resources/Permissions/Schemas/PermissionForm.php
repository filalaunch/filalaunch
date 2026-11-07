<?php

namespace App\Filament\Resources\Permissions\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class PermissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->regex('/^[a-z0-9_]+(\.[a-z0-9_]+)?$/')
                            ->validationMessages(['regex' => 'Use the format resource.ability, e.g. invoices.export'])
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('guard_name', 'web'))
                            ->helperText('Format: resource.ability — e.g. users.view_any, invoices.export. Renaming a permission used in a policy will break that check.'),

                        Hidden::make('guard_name')
                            ->default('web'),
                    ]),
            ]);
    }
}
