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
    | Billing
    |--------------------------------------------------------------------------
    |
    | Plans, subscriptions and payments. Prices are stored in the smallest unit
    | (cents). Bank details, enabled plan types and gateways are managed in the
    | admin panel (Billing → Billing settings). A currency settings screen comes later.
    |
    */

    'billing' => [
        'currency' => env('FILALAUNCH_CURRENCY', 'USD'),

        // Days access continues after a subscription period ends (time to renew by bank transfer).
        'grace_days' => (int) env('FILALAUNCH_GRACE_DAYS', 3),

        // Payment references look like FL-2026-7K3Q9X.
        'reference_prefix' => 'FL',
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

        // Models that the Modules page never lists or deletes (User, Role and Permission are always protected).
        'protected_models' => [
            // App\Models\Plan::class,
        ],

        // Deleted modules (files + table rows) are kept in storage/app/filalaunch/trash for this many
        // days so they can be restored, then removed automatically. 0 = delete permanently, no trash.
        'trash_days' => (int) env('FILALAUNCH_TRASH_DAYS', 7),
    ],

];
