<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Removes a user's authenticator app + recovery codes, so they can log in
 * with password only and set 2FA up again from their profile.
 */
class ResetTwoFactorAction
{
    public static function make(): Action
    {
        return Action::make('resetTwoFactor')
            ->label('Reset 2FA')
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This removes the user\'s authenticator app and recovery codes. They can set 2FA up again from their profile.')
            ->visible(fn (User $record): bool => auth()->user()->can('resetTwoFactor', $record))
            ->action(function (User $record, Action $action): void {
                $record->saveAppAuthenticationSecret(null);
                $record->saveAppAuthenticationRecoveryCodes(null);

                $action->success();
            })
            ->successNotificationTitle('Two-factor authentication reset');
    }
}
