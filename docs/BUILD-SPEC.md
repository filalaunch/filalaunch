# FilaLaunch — Build Spec

> Single source of truth for how FilaLaunch is built.
> Read this before changing code — whether you are a human contributor or an AI coding tool (Claude Code, Cursor, Copilot…).
> **Update this file in the same change whenever you alter architecture, conventions, or invariants.**

| | |
|---|---|
| Product | FilaLaunch — SaaS starter kit · [filalaunch.com](https://filalaunch.com) |
| Repository | `github.com/filalaunch/filalaunch` (MIT) |
| Spec version | 1.0 — Step 1 complete |
| Last updated | 2026-11-07 |

---

## 1. Purpose & scope

FilaLaunch gives SaaS founders a working business-operations layer on Laravel + Filament, so they start on their product instead of rebuilding auth, roles and admin screens.

**Current scope (Step 1):** a single Filament **admin panel** with complete authentication and dynamic, database-driven access control.

**Explicitly out of scope right now** (do not add without a decision recorded in §13):

- A separate end-user panel (e.g. `/app`)
- Teams / multi-tenancy (Spatie `teams` is `false`)
- Filament Shield or any other authorization package
- SPA front-end

---

## 2. Tech stack

| Layer | Choice | Version (lockfile) |
|---|---|---|
| Language | PHP | ^8.2 (tested on 8.3) |
| Framework | Laravel | ^12.0 (12.71) |
| Admin UI | FilamentPHP | ^4.0 (4.16) — Livewire 3 |
| Roles/permissions | spatie/laravel-permission | ^8.5 |
| 2FA | Filament built-in app authentication (pragmarx/google2fa) | bundled with Filament |
| Database | SQLite (default) — MySQL/PostgreSQL supported | — |
| Queue | `sync` by default (no worker needed); `database`/`redis` + worker optional | — |
| Cache / session | `database` driver | — |
| Mail (dev) | `log` driver | — |
| Code style | Laravel Pint (default preset) | — |

---

## 3. Directory map

```
app/
├── Console/Commands/SyncPermissionsCommand.php   filalaunch:sync-permissions
├── Filament/
│   ├── Concerns/SyncsPermissionCheckboxes.php    trait for Create/Edit pages (Role, User)
│   ├── Pages/Auth/Register.php                   extends Filament Register; assigns default role
│   ├── Resources/{Plural}/                       one folder per resource (Filament v4 layout)
│   │   ├── {Model}Resource.php
│   │   ├── Schemas/{Model}Form.php
│   │   ├── Tables/{Plural}Table.php
│   │   ├── Pages/List…, Create…, Edit…
│   │   └── Actions/ (optional, reusable actions)
│   └── Support/PermissionCheckboxes.php          grouped permission CheckboxLists
├── Models/
│   ├── User.php          HasRoles, MustVerifyEmail, FilamentUser, 2FA contracts
│   ├── Role.php          extends Spatie Role (+ protectedNames(), isProtected(), isSuperAdmin())
│   └── Permission.php    extends Spatie Permission (+ getGroup())
├── Policies/             one policy per model, auto-discovered (App\Models\X → App\Policies\XPolicy)
├── Providers/
│   ├── AppServiceProvider.php            Gate::after Super Admin rule
│   └── Filament/AdminPanelProvider.php   panel id "admin", path "/admin", auth features
└── Support/Permissions/PermissionSync.php  permission generator (used by command, seeder, UI button)
config/filalaunch.php            kit settings (super admin, role names, abilities)
config/permission.php            Spatie config — models point to App\Models\Role / Permission
database/migrations/             users table includes 2FA columns
database/seeders/                DatabaseSeeder → RolesAndPermissionsSeeder → SuperAdminSeeder
stubs/filalaunch-policy.stub     template used by sync-permissions --policies
docs/BUILD-SPEC.md               this file
CLAUDE.md                        AI entry point → this file
art/                             logo assets (README)
```

---

## 4. Configuration contract

### `.env`

| Key | Required | Used by |
|---|---|---|
| `SUPERADMIN_NAME` | no (default `Super Admin`) | `SuperAdminSeeder` |
| `SUPERADMIN_EMAIL` | yes, to seed Super Admin | `SuperAdminSeeder` |
| `SUPERADMIN_PASSWORD` | yes, to seed Super Admin | `SuperAdminSeeder` |

**Rule:** never call `env()` outside `config/*.php`. Read values with `config('filalaunch.…')` (config caching breaks `env()` elsewhere).

### `config/filalaunch.php`

```php
'super_admin' => ['name', 'email', 'password'],          // from .env
'roles' => [
    'super_admin' => 'super_admin',   // bypass role
    'admin'       => 'admin',         // seeded staff role
    'default'     => 'user',          // given on self-registration
],
'permissions' => [
    'abilities'             => ['view_any', 'view', 'create', 'update', 'delete'],
    'soft_delete_abilities' => ['restore', 'force_delete'],
],
```

**Rule:** never hard-code role names. Use `config('filalaunch.roles.*')`.

---

## 5. Authentication

Configured in `AdminPanelProvider`:

```php
->login()
->registration(Register::class)        // App\Filament\Pages\Auth\Register
->passwordReset()
->emailVerification()                  // required
->profile(isSimple: false)             // profile inside the panel layout
->multiFactorAuthentication([
    AppAuthentication::make()->recoverable(),
])                                     // optional per user
```

| Concern | Implementation |
|---|---|
| Panel access | `User::canAccessPanel()` returns `true` for panel `admin`. Every **registered** user may enter; verification is enforced by the panel; all content is gated by policies. |
| Registration | `Register::handleRegistration()` calls parent, then `assignRole(config('filalaunch.roles.default'))`. Runs in Filament's DB transaction. |
| Email verification | `User implements MustVerifyEmail`. Filament sends `Filament\Auth\Notifications\VerifyEmail` (a queued notification). With the default `QUEUE_CONNECTION=sync` it is sent immediately; with `database`/`redis` a worker (or cron `queue:work --stop-when-empty`) is required. New features must keep working under `sync` — never assume a worker exists. |
| 2FA storage | `users.app_authentication_secret` (text, `encrypted` cast) and `users.app_authentication_recovery_codes` (text, `encrypted:array` cast). Casts + `hidden` come from Filament traits `InteractsWithAppAuthentication` and `InteractsWithAppAuthenticationRecovery`. |
| 2FA helper | `User::hasAppAuthenticationEnabled()` → `filled(app_authentication_secret)` |
| 2FA reset by admin | `ResetTwoFactorAction` sets both values to `null` via `saveAppAuthentication*()` |

---

## 6. Authorization model

### 6.1 Building blocks

- **Spatie Permission** stores roles, permissions and assignments (role-level and direct user-level).
- **Laravel policies** decide every Filament action. Filament calls them automatically (`viewAny` hides menu items, `create` hides buttons, etc.).
- **No Shield**, no `Gate::define` for resources.

### 6.2 Permission naming

```
{prefix}.{ability}
prefix  = Str::snake(Str::plural(class_basename(Model)))   // User → users, BlogPost → blog_posts
ability = view_any | view | create | update | delete [| restore | force_delete]
```

Custom permissions follow the same shape: `invoices.export`, `reports.download`.
Permissions without a dot are grouped under **Other** in the UI.

### 6.3 Policy ↔ permission mapping

| Policy method | Permission |
|---|---|
| `viewAny` | `{prefix}.view_any` |
| `view` | `{prefix}.view` |
| `create` | `{prefix}.create` |
| `update` | `{prefix}.update` |
| `delete`, `deleteAny` | `{prefix}.delete` |
| `restore`, `restoreAny` | `{prefix}.restore` |
| `forceDelete`, `forceDeleteAny` | `{prefix}.force_delete` |

Policy bodies **must** check via `$user->can('{prefix}.{ability}')` — never `hasRole()` — so roles stay data-driven.

### 6.4 Super Admin

```php
// AppServiceProvider::boot()
Gate::after(fn ($user, $ability, $result) =>
    $result === null && $user instanceof User && $user->isSuperAdmin() ? true : null);
```

- `Gate::after` (not `before`) is deliberate: an explicit `false` from a policy safety rule still wins over Super Admin.
- Inside a policy, `$user->can('users.delete')` for a Super Admin resolves to `true` via this hook (Spatie's gate returns `null` when the permission isn't held).
- The `super_admin` role therefore needs **no** permissions attached.

### 6.5 Effective permissions

`role permissions ∪ direct permissions` — handled by Spatie (`$user->can()`, `hasPermissionTo()`).

---

## 7. Invariants (must not break)

Any change must keep all of these true. Each is enforced where noted.

| # | Invariant | Enforced in |
|---|---|---|
| I1 | A user cannot delete themselves | `UserPolicy::delete` |
| I2 | The last Super Admin cannot be deleted | `UserPolicy::delete` |
| I3 | Only a Super Admin can edit or delete a Super Admin | `UserPolicy::update`, `delete` |
| I4 | Only a Super Admin can assign the `super_admin` role | `UserForm` roles `modifyQueryUsing` |
| I5 | Users cannot change their own roles or direct permissions | `UserForm` `disabled($isSelf)` (disabled fields aren't dehydrated, so nothing is synced) |
| I6 | `super_admin` and default `user` roles cannot be deleted | `RolePolicy::delete` via `Role::isProtected()` |
| I7 | `super_admin` role cannot be edited; protected role names cannot be renamed | `RolePolicy::update`, `RoleForm` name `disabled` |
| I8 | Self-registered users always receive the default role | `Register::handleRegistration` |
| I9 | 2FA secrets and recovery codes are encrypted at rest and hidden from serialization | Filament traits on `User` |
| I10 | Bulk deletes authorize each record individually | `DeleteBulkAction::authorizeIndividualRecords('delete')` in every table |
| I11 | Seeders are idempotent; Super Admin password is never overwritten on re-seed | `SuperAdminSeeder` uses `firstOrCreate` |

---

## 8. Data model

| Table | Notes |
|---|---|
| `users` | default Laravel columns + `app_authentication_secret` (text, nullable) + `app_authentication_recovery_codes` (text, nullable) |
| `roles`, `permissions` | Spatie, `guard_name = web` |
| `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Spatie pivots (polymorphic) |
| `sessions`, `cache`, `jobs`, `failed_jobs`, `password_reset_tokens` | Laravel defaults |

**Dev-phase rule:** editing existing migrations is allowed (projects reset with `migrate:fresh --seed`). Once a stable release is tagged, switch to additive migrations only and record it in §13.

---

## 9. UI conventions (Filament v4)

- Generate resources with `php artisan make:filament-resource {Model} --not-embedded --record-title-attribute=name`, giving separate `Schemas/`, `Tables/` and `Pages/` files.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g. `Heroicon::OutlinedUsers`).
- Layout components (`Section`, `Fieldset`, `Grid`, `Text`) come from `Filament\Schemas\Components`; fields from `Filament\Forms\Components`; actions from `Filament\Actions`.
- Tables use `->recordActions([...])` and `->toolbarActions([...])`.
- Navigation: access-control resources live in group **Access Control** (sort 1 Users, 2 Roles, 3 Permissions). New feature modules get their own group.
- Forms use `->columns(1)` at schema level and `Section`s for grouping.
- Reusable actions go in `Resources/{Plural}/Actions/{Name}Action.php` exposing `static make(): Action`.
- Panel primary color: `Color::Amber` (brand color change is a pending decision).

### Grouped permission checkboxes

`PermissionCheckboxes::make(bool|Closure $disabled = false)` returns one `Fieldset` per permission group, each holding a `CheckboxList` with state path `permission_ids.{group}`.

Create/Edit pages that use it must:

```php
use SyncsPermissionCheckboxes;

// Edit pages only
protected function mutateFormDataBeforeFill(array $data): array
{
    $data[PermissionCheckboxes::STATE_PATH] = PermissionCheckboxes::stateFor($this->record);
    return $data;
}

protected function mutateFormDataBeforeSave(array $data): array   // Create: mutateFormDataBeforeCreate
{
    return $this->pullPermissionIds($data);
}

protected function afterSave(): void                              // Create: afterCreate
{
    $this->syncPendingPermissions();
}
```

If the checkboxes are disabled, the key is absent → permissions are left untouched.

---

## 10. Recipes

### 10.1 Add a new CRUD resource with permissions

1. `php artisan make:model Project -m` → add columns → `php artisan migrate`
2. `php artisan make:filament-resource Project --not-embedded --record-title-attribute=name`
3. `php artisan filalaunch:sync-permissions --policies` → creates `projects.*` permissions + `app/Policies/ProjectPolicy.php` from the stub
4. Add `->authorizeIndividualRecords('delete')` to the table's `DeleteBulkAction` (I10)
5. Add any safety rules to the policy **before** the `$user->can(...)` return
6. Optionally grant the new permissions to `admin` in `RolesAndPermissionsSeeder`
7. Update §3 / §11 of this spec

### 10.2 Add a custom permission

1. Create it in the panel (Permissions → New) or in a seeder via `Permission::firstOrCreate(['name' => 'projects.export', 'guard_name' => 'web'])`
2. Check it with `$user->can('projects.export')` (or `->authorize(fn () => auth()->user()->can('projects.export'))` on a Filament action)

### 10.3 Add a seeded role

Add a `Role::findOrCreate('name', 'web')->syncPermissions([...])` line to `RolesAndPermissionsSeeder`. If the role must be undeletable, add a config key under `filalaunch.roles` and include it in `Role::protectedNames()`.

### 10.4 Add an auth feature to the panel

Edit only `AdminPanelProvider::panel()`. To customise a Filament auth page, extend it under `app/Filament/Pages/Auth/` and pass the class (as with `Register`).

### 10.5 Add a second panel later

Create it with `php artisan make:filament-panel app`, then update `User::canAccessPanel()` to decide per `$panel->getId()`, and point `PermissionSync`/command at the right panel (`--panel=`).

---

## 11. Feature status

| Step | Feature | Status |
|---|---|---|
| 1 | Admin panel: login, registration, email verification, password reset, profile | ✅ |
| 1 | Optional 2FA (authenticator app + recovery codes), admin 2FA reset | ✅ |
| 1 | Super Admin seeded from `.env` | ✅ |
| 1 | Users / Roles / Permissions resources, role-level + direct permissions | ✅ |
| 1 | `filalaunch:sync-permissions [--policies]` + UI sync button | ✅ |
| — | Next modules | to be defined (see filalaunch.com) |

---

## 12. Working rules for contributors and AI tools

1. **Read before writing.** Check this spec and the files you touch; follow existing patterns.
2. **Surgical changes.** Change only what the task needs; don't rewrite working files or reformat unrelated code.
3. **Respect the invariants (§7)** and the out-of-scope list (§1).
4. **Authorization goes through policies + permissions.** No role-name checks in resources, no Shield.
5. **No `env()` outside config.** No hard-coded role names.
6. **Use Filament v4 APIs** (`Filament\Schemas\…`, `Filament\Actions\…`, `Heroicon` enum). Don't copy v3 snippets (`Forms\Form`, `Tables\Actions\…`).
7. **Verify** before finishing: `php artisan migrate:fresh --seed`, open the affected pages, `vendor/bin/pint`.
8. **Update this spec** (§3, §7, §11, §13) in the same change.

### Verification checklist

- [ ] `php artisan migrate:fresh --seed` succeeds and prints "Super Admin ready"
- [ ] `/admin/login`, `/admin/register`, `/admin/password-reset/request` return 200
- [ ] Super Admin sees Users, Roles, Permissions; a `user`-role account sees none of them
- [ ] Editing a role's permissions persists after save
- [ ] Invariants I1–I11 still hold
- [ ] `vendor/bin/pint --test` passes

---

## 13. Decision log

| Date | Decision |
|---|---|
| 2026-11-07 | Fresh start on Laravel 12 + Filament 4 + Spatie Permission 8, SQLite, log mailer |
| 2026-11-07 | Admin panel only; no user panel, no teams for now |
| 2026-11-07 | No Filament Shield — Filament resources protected by Laravel policies |
| 2026-11-07 | Permission naming `{resource}.{ability}`; abilities view_any, view, create, update, delete (+ restore, force_delete with SoftDeletes) |
| 2026-11-07 | Roles: `super_admin` (Gate::after bypass), `admin`, `user` (default on registration) |
| 2026-11-07 | Registered users may enter the panel after email verification; content gated by policies |
| 2026-11-07 | 2FA optional for everyone |
| 2026-11-07 | `filalaunch:sync-permissions` generates permissions (and optionally policies) for new resources |
| 2026-11-07 | Dev phase: existing migrations may be edited |
| 2026-11-07 | Panel color stays Amber for now |
| 2026-11-07 | Default `QUEUE_CONNECTION=sync` so the kit runs on shared hosting without a worker; README documents cron and Supervisor setups |
