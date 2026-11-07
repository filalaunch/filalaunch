<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.view_any');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        // super_admin already bypasses every check, so editing it is meaningless.
        if ($role->isSuperAdmin()) {
            return false;
        }

        return $user->can('roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        if ($role->isProtected()) {
            return false;
        }

        return $user->can('roles.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('roles.delete');
    }
}
