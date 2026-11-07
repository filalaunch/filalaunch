<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super Admin gets every ability that wasn't explicitly decided.
        // Gate::after (not before) so explicit `false` safety rules in policies
        // still apply — e.g. no self-delete, no deleting the super_admin role.
        Gate::after(function ($user, string $ability, mixed $result) {
            if ($result === null && $user instanceof User && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });
    }
}
