<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions\PermissionSync;

/**
 * Fallback policy for every Eloquent model without its own App\Policies\{Model}Policy
 * (wired in AppServiceProvider). No permission = no access.
 *
 * Laravel passes the model class/record to before(), so the permission is resolved
 * there and always decides. The inherited methods exist so Filament (which uses
 * method_exists()) recognises every ability.
 */
class DefaultPolicy extends ResourcePolicy
{
    public function before(User $user, string $ability, mixed $subject = null): bool
    {
        $model = is_object($subject) ? $subject::class : $subject;

        return is_string($model) && $user->can(PermissionSync::permissionFor($model, $ability));
    }
}
