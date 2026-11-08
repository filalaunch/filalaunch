<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions\PermissionSync;
use Filament\Facades\Filament;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(PermissionSync $sync): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Standard permissions for every Filament resource (users.*, roles.*, permissions.*).
        foreach (Filament::getPanels() as $panel) {
            $sync->sync($panel->getId());
        }

        // Staff entry to /admin once the customer panel is on (Super Admin always has it).
        Permission::firstOrCreate(['name' => 'admin.access', 'guard_name' => 'web']);

        // 2. Default roles.
        Role::findOrCreate(config('filalaunch.roles.super_admin'), 'web'); // bypasses all checks — no permissions needed
        Role::findOrCreate(config('filalaunch.roles.default'), 'web');     // self-registered users — no permissions by default

        Role::findOrCreate(config('filalaunch.roles.admin'), 'web')
            ->syncPermissions([
                'users.view_any', 'users.view', 'users.create', 'users.update', 'users.delete',
                'roles.view_any', 'roles.view',
                'permissions.view_any', 'permissions.view',
                'admin.access',
            ]);
    }
}
