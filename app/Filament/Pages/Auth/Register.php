<?php

namespace App\Filament\Pages\Auth;

use App\Models\Role;
use Filament\Auth\Pages\Register as BaseRegister;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

class Register extends BaseRegister
{
    /**
     * Create the user and assign the default role (config: filalaunch.roles.default).
     * Runs inside Filament's registration transaction.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $user = parent::handleRegistration($data);

        $user->assignRole(Role::findOrCreate(config('filalaunch.roles.default'), 'web'));

        return $user;
    }
}
