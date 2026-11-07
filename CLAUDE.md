# FilaLaunch — AI assistant guide

Laravel 12 + FilamentPHP 4 SaaS starter kit. Admin panel at `/admin`, Spatie Permission, policy-based authorization.

**Read the full build spec before making changes:**

@docs/BUILD-SPEC.md

## Quick rules

- Surgical changes only — touch what the task needs, keep existing patterns.
- Authorization = Laravel policies calling `$user->can('{resource}.{ability}')`. No Filament Shield, no role-name checks in resources.
- Never call `env()` outside `config/`. Role names come from `config('filalaunch.roles.*')`.
- Use Filament v4 APIs (`Filament\Schemas\Components`, `Filament\Actions`, `Heroicon` enum).
- New resources need no policy or command: permissions are auto-created and `DefaultPolicy` denies access until the Super Admin grants them. Write a `{Model}Policy extends ResourcePolicy` only for extra safety rules.
- Never assign permissions automatically.
- Billing: all subscription/payment changes go through `App\Support\Billing\Billing`; module access through `ModuleAccess` (BUILD-SPEC §15). Prices are integers in minor units.
- New CRUD modules: prefer `php artisan filalaunch:make-module` (see BUILD-SPEC §14), then edit the generated files. Remove one with `php artisan filalaunch:delete-module` (§14.5).
- Keep the invariants in BUILD-SPEC §7 true.
- Verify with `php artisan migrate:fresh --seed` and `vendor/bin/pint`.
- Update `docs/BUILD-SPEC.md` when architecture, conventions or features change.
