<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Support\PermissionCheckboxes;
use App\Models\Role;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Role')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('guard_name', 'web'))
                            // Protected role names are referenced in config — renaming would break them.
                            ->disabled(fn (?Role $record): bool => (bool) $record?->isProtected())
                            ->helperText(fn (?Role $record): string => $record?->isProtected()
                                ? 'System role — name can\'t be changed.'
                                : 'Lowercase with underscores, e.g. content_editor.'),

                        Hidden::make('guard_name')
                            ->default('web'),
                    ]),

                Section::make('Permissions')
                    ->description('Users with this role get every permission checked below.')
                    ->schema(fn (): array => PermissionCheckboxes::make()),
            ]);
    }
}
