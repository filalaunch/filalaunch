<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view_any');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        // Only a Super Admin may edit a Super Admin.
        if ($model->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->can('users.update');
    }

    public function delete(User $user, User $model): bool
    {
        // Nobody deletes themselves.
        if ($user->is($model)) {
            return false;
        }

        if ($model->isSuperAdmin()) {
            // Only a Super Admin may delete a Super Admin, and never the last one.
            if (! $user->isSuperAdmin() || User::role(config('filalaunch.roles.super_admin'))->count() <= 1) {
                return false;
            }
        }

        return $user->can('users.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('users.delete');
    }

    /**
     * Reset another user's 2FA (authenticator app + recovery codes).
     */
    public function resetTwoFactor(User $user, User $model): bool
    {
        return $model->hasAppAuthenticationEnabled() && $this->update($user, $model);
    }
}
