<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Creates the Super Admin from SUPERADMIN_* values in .env.
     * Safe to re-run: an existing user is kept (password is not overwritten).
     */
    public function run(): void
    {
        $config = config('filalaunch.super_admin');

        if (blank($config['email']) || blank($config['password'])) {
            $this->command?->warn('SuperAdminSeeder skipped: set SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD in .env');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => $config['email']],
            [
                'name' => $config['name'] ?: 'Super Admin',
                'password' => $config['password'], // hashed by the model cast
            ],
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $user->assignRole(Role::findOrCreate(config('filalaunch.roles.super_admin'), 'web'));

        $this->command?->info("Super Admin ready: {$user->email}");
    }
}
