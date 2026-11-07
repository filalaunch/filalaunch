<?php

namespace App\Policies;

/**
 * No extra rules — all checks (permissions.view_any, permissions.create, ...)
 * come from ResourcePolicy. Kept as an explicit example; deleting this file
 * changes nothing, because DefaultPolicy would guard the model the same way.
 */
class PermissionPolicy extends ResourcePolicy
{
    //
}
