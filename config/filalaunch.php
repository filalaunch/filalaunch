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

    /*
    |--------------------------------------------------------------------------
    | Module Builder
    |--------------------------------------------------------------------------
    |
    | Generates migration, model, factory, seeder, policy and Filament resource
    | from the "Module Builder" page (Super Admin only) or
    | `php artisan filalaunch:make-module`.
    |
    | Enabled by default only when APP_ENV=local. Set FILALAUNCH_BUILDER_ENABLED=true
    | to use it elsewhere (the web server user must be able to write to app/ and database/).
    |
    */

    'builder' => [
        'enabled' => (bool) env('FILALAUNCH_BUILDER_ENABLED', env('APP_ENV', 'production') === 'local'),
    ],

];
