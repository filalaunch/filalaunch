<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /**
     * Roles that cannot be deleted (and, for super_admin, not edited).
     *
     * @return list<string>
     */
    public static function protectedNames(): array
    {
        return [
            config('filalaunch.roles.super_admin'),
            config('filalaunch.roles.default'),
        ];
    }

    public function isProtected(): bool
    {
        return in_array($this->name, static::protectedNames(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === config('filalaunch.roles.super_admin');
    }
}
