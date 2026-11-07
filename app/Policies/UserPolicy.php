<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard checks (users.view_any, users.create, ...) are inherited from ResourcePolicy.
 * Only the safety rules live here.
 */
class UserPolicy extends ResourcePolicy
{
    /**
     * @param  User  $record
     */
    public function update(User $user, Model $record): bool
    {
        // Only a Super Admin may edit a Super Admin.
        if ($record->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return parent::update($user, $record);
    }

    /**
     * @param  User  $record
     */
    public function delete(User $user, Model $record): bool
    {
        // Nobody deletes themselves.
        if ($user->is($record)) {
            return false;
        }

        if ($record->isSuperAdmin()) {
            // Only a Super Admin may delete a Super Admin, and never the last one.
            if (! $user->isSuperAdmin() || User::role(config('filalaunch.roles.super_admin'))->count() <= 1) {
                return false;
            }
        }

        return parent::delete($user, $record);
    }

    /**
     * Reset another user's 2FA (authenticator app + recovery codes).
     */
    public function resetTwoFactor(User $user, User $record): bool
    {
        return $record->hasAppAuthenticationEnabled() && $this->update($user, $record);
    }
}
