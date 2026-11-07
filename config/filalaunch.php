<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    |
    | Credentials used by SuperAdminSeeder. Set them in your .env file.
    | The seeder skips creating the user when email or password is empty.
    |
    */

    'super_admin' => [
        'name' => env('SUPERADMIN_NAME', 'Super Admin'),
        'email' => env('SUPERADMIN_EMAIL'),
        'password' => env('SUPERADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | "super_admin" bypasses every permission check (except safety rules in
    | policies). "default" is assigned to every self-registered user.
    | Both roles are protected from deletion in the admin panel.
    |
    */

    'roles' => [
        'super_admin' => 'super_admin',
        'admin' => 'admin',
        'default' => 'user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Abilities
    |--------------------------------------------------------------------------
    |
    | Abilities generated per resource by `php artisan filalaunch:sync-permissions`.
    | Names follow the "{resource}.{ability}" format, e.g. "users.view_any".
    | Soft-delete abilities are only added when the model uses SoftDeletes.
    |
    */

    'permissions' => [
        'abilities' => ['view_any', 'view', 'create', 'update', 'delete'],
        'soft_delete_abilities' => ['restore', 'force_delete'],
    ],

];
