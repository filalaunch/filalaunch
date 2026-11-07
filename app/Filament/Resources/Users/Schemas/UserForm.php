<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Support\PermissionCheckboxes;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        // Nobody changes their own roles / direct permissions (prevents self-escalation & lock-out).
        $isSelf = fn (?User $record): bool => $record?->is(auth()->user()) ?? false;

        return $schema
            ->columns(1)
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state)) // hashed by the model cast
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave blank to keep the current password.' : null)
                            ->confirmed(),

                        TextInput::make('password_confirmation')
                            ->label('Confirm password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation, $get): bool => $operation === 'create' || filled($get('password')))
                            ->dehydrated(false),

                        Toggle::make('email_verified_at')
                            ->label('Email verified')
                            ->formatStateUsing(fn ($state): bool => filled($state))
                            ->dehydrateStateUsing(fn (bool $state, ?User $record) => $state ? ($record?->email_verified_at ?? now()) : null)
                            ->default(true),
                    ]),

                Section::make('Roles')
                    ->description(fn (?User $record): ?string => $isSelf($record) ? 'You can\'t change your own roles.' : null)
                    ->schema([
                        Select::make('roles')
                            ->hiddenLabel()
                            ->relationship(
                                name: 'roles',
                                titleAttribute: 'name',
                                // Only a Super Admin can hand out the super_admin role.
                                modifyQueryUsing: fn (Builder $query) => auth()->user()?->isSuperAdmin()
                                    ? $query
                                    : $query->where('name', '!=', config('filalaunch.roles.super_admin')),
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->default(fn (): array => Role::query()->where('name', config('filalaunch.roles.default'))->pluck('id')->all())
                            ->disabled($isSelf),
                    ]),

                Section::make('Direct permissions')
                    ->description('Extra permissions for this user, on top of what their roles grant.')
                    ->collapsible()
                    ->collapsed(fn (string $operation): bool => $operation === 'create')
                    ->hidden(fn (?User $record): bool => (bool) $record?->isSuperAdmin())
                    ->schema(fn (): array => PermissionCheckboxes::make(disabled: $isSelf)),
            ]);
    }
}
