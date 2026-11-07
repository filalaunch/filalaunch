<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard checks (roles.view_any, roles.create, ...) are inherited from ResourcePolicy.
 * Only the safety rules live here.
 */
class RolePolicy extends ResourcePolicy
{
    /**
     * @param  Role  $record
     */
    public function update(User $user, Model $record): bool
    {
        // super_admin already bypasses every check, so editing it is meaningless.
        if ($record->isSuperAdmin()) {
            return false;
        }

        return parent::update($user, $record);
    }

    /**
     * @param  Role  $record
     */
    public function delete(User $user, Model $record): bool
    {
        if ($record->isProtected()) {
            return false;
        }

        return parent::delete($user, $record);
    }
}
