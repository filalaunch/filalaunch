<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\DefaultPolicy;
use App\Support\Billing\ModuleAccess;
use App\Support\Settings;
use App\Support\SystemSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Settings saved in System → Settings override .env defaults (before panels register).
        SystemSettings::apply();

        // Per-request caches (also safe under Octane).
        $this->app->scoped(Settings::class);
        $this->app->scoped(ModuleAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // migrate:fresh wipes the settings table — drop the boot cache with it.
        Event::listen(MigrationsEnded::class, fn () => SystemSettings::forgetCache());

        // Super Admin gets every ability that wasn't explicitly decided.
        // Gate::after (not before) so explicit `false` safety rules in policies
        // still apply — e.g. no self-delete, no deleting the super_admin role.
        Gate::after(function ($user, string $ability, mixed $result) {
            if ($result === null && $user instanceof User && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });

        // Every model is guarded: App\Policies\{Model}Policy if it exists,
        // otherwise DefaultPolicy ("{resource}.{ability}" permission, no permission = no access).
        Gate::guessPolicyNamesUsing(function (string $class): array {
            $policy = Str::startsWith($class, 'App\\Models\\')
                ? 'App\\Policies\\'.Str::after($class, 'App\\Models\\').'Policy'
                : 'App\\Policies\\'.class_basename($class).'Policy';

            return is_subclass_of($class, Model::class)
                ? [$policy, DefaultPolicy::class]
                : [$policy];
        });
    }
}
