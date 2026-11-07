# FilaLaunch — Build Spec

> Single source of truth for how FilaLaunch is built.
> Read this before changing code — whether you are a human contributor or an AI coding tool (Claude Code, Cursor, Copilot…).
> **Update this file in the same change whenever you alter architecture, conventions, or invariants.**

| | |
|---|---|
| Product | FilaLaunch — SaaS starter kit · [filalaunch.com](https://filalaunch.com) |
| Repository | `github.com/filalaunch/filalaunch` (MIT) |
| Spec version | 1.2 — Step 3 complete (Module Builder) |
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
├── Console/Commands/
│   ├── SyncPermissionsCommand.php                filalaunch:sync-permissions
│   └── MakeModuleCommand.php                     filalaunch:make-module (prompts or flags)
├── Filament/
│   ├── Concerns/SyncsPermissionCheckboxes.php    trait for Create/Edit pages (Role, User)
│   ├── Pages/Auth/Register.php                   extends Filament Register; assigns default role
│   ├── Pages/ModuleBuilder.php                   Module Builder page (Super Admin + builder enabled)
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
├── Policies/
│   ├── ResourcePolicy.php   abstract base: every ability → "{prefix}.{ability}" permission
│   ├── DefaultPolicy.php    fallback for models without their own policy (no permission = no access)
│   └── {Model}Policy.php    optional, only for extra safety rules (User, Role, Permission)
├── Providers/
│   ├── AppServiceProvider.php            Gate::after Super Admin rule
│   └── Filament/AdminPanelProvider.php   panel id "admin", path "/admin", auth features
└── Support/
    ├── ModuleBuilder/
    │   ├── ModuleDefinition.php   normalised input + validation + naming rules
    │   ├── ModuleFiles.php        renders stubs; safe edits to existing files
    │   ├── ModuleBuilder.php      orchestration, rollback, cache refresh
    │   └── ModuleBuilderException.php
    └── Permissions/PermissionSync.php  permission generator (used by command, seeder, UI button, builder)
config/filalaunch.php            kit settings (super admin, role names, abilities)
config/permission.php            Spatie config — models point to App\Models\Role / Permission
database/migrations/             users table includes 2FA columns
database/seeders/                DatabaseSeeder → RolesAndPermissionsSeeder → SuperAdminSeeder
stubs/filalaunch-policy.stub     policy scaffold (extends ResourcePolicy) used by sync-permissions --policies and the builder
stubs/module-builder/            migration / model / factory / seeder templates for the Module Builder
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
| `FILALAUNCH_BUILDER_ENABLED` | no (default `true` only when `APP_ENV=local`) | `config('filalaunch.builder.enabled')` → Module Builder |

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

Defined once in `PermissionSync::ABILITY_MAP`, resolved by `PermissionSync::permissionFor($modelClass, $ability)`.

| Policy method | Permission |
|---|---|
| `viewAny` | `{prefix}.view_any` |
| `view` | `{prefix}.view` |
| `create`, `replicate` | `{prefix}.create` |
| `update`, `reorder`, `attach(Any)`, `detach(Any)`, `associate(Any)`, `dissociate(Any)` | `{prefix}.update` |
| `delete`, `deleteAny` | `{prefix}.delete` |
| `restore`, `restoreAny` | `{prefix}.restore` |
| `forceDelete`, `forceDeleteAny` | `{prefix}.force_delete` |
| any other ability, e.g. `export` | `{prefix}.export` (snake_case) |

Policies **must** check permissions — never `hasRole()` — so roles stay data-driven.

### 6.4 Policy resolution (secure by default)

```php
// AppServiceProvider::boot()
Gate::guessPolicyNamesUsing(fn (string $class) =>
    is_subclass_of($class, Model::class)
        ? ['App\Policies\{Model}Policy', DefaultPolicy::class]   // first existing class wins
        : ['App\Policies\{Model}Policy']);
```

| Class | Role |
|---|---|
| `ResourcePolicy` (abstract) | Real methods for **every** ability Filament checks (Filament uses `method_exists()`; a missing method would mean *allowed*). Each calls `$this->allows($user, '<ability>')`. Model is derived from the class name (`UserPolicy` → `App\Models\User`) or `protected ?string $model`. `__call` handles custom abilities. |
| `DefaultPolicy` | Extends `ResourcePolicy`; `before($user, $ability, $subject)` resolves the permission from the model class/record Laravel passes in and always decides. Guards every model without its own policy. |
| `{Model}Policy` | Optional. Extends `ResourcePolicy`, overrides only methods that need safety rules, then returns `parent::method(...)`. Override signatures must keep the parent types (`Model $record`) — use `@param` for the concrete type. |

The panel also runs `->strictAuthorization()`: if an ability ever has no policy method, Filament throws instead of allowing.

### 6.5 Automatic permission creation

```php
// AdminPanelProvider
->bootUsing(fn (Panel $panel) => app(PermissionSync::class)->ensureSynced($panel->getId()))
```

- On every panel request, `ensureSynced()` compares the permissions all registered resources need (`expectedPermissions()`) with Spatie's **cached** permission list. Only if something is missing does it run `sync()` (`firstOrCreate`, then flush Spatie's cache).
- Covers resources added by artisan, by hand, or generated from a GUI — nothing to run.
- Permissions are **only created** — never assigned to roles/users and never deleted. Leftovers from removed resources are deleted manually on the Permissions page.
- A `QueryException` (database not migrated yet) is swallowed so a fresh install doesn't crash.
- Manual fallbacks: `php artisan filalaunch:sync-permissions`, the **Sync from resources** button, and `RolesAndPermissionsSeeder`.

### 6.6 Super Admin

```php
// AppServiceProvider::boot()
Gate::after(fn ($user, $ability, $result) =>
    $result === null && $user instanceof User && $user->isSuperAdmin() ? true : null);
```

- `Gate::after` (not `before`) is deliberate: an explicit `false` from a policy safety rule still wins over Super Admin.
- Inside a policy, `$user->can('users.delete')` for a Super Admin resolves to `true` via this hook (Spatie's gate returns `null` when the permission isn't held).
- The `super_admin` role therefore needs **no** permissions attached.

### 6.7 Effective permissions

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
| I12 | Every model is guarded; no permission = no access (except Super Admin) | `Gate::guessPolicyNamesUsing` → `DefaultPolicy`, `strictAuthorization()` |
| I13 | Every ability Filament checks exists as a real method on `ResourcePolicy` | `ResourcePolicy` (add a method when Filament adds an ability) |
| I14 | Automation only creates permissions — never assigns or deletes them | `PermissionSync` |
| I15 | Module Builder runs only when `builder.enabled` is true, and its page only for Super Admin | `ModuleBuilder::enabled()`, `ModuleBuilder` page `canAccess()`, command check |
| I16 | A failed module build leaves no trace (files deleted, edits restored, table + migration row removed) | `ModuleBuilder::rollback()` |
| I17 | Edits to existing files only append (a relation method, an import, a seeder line) and are syntax-checked before writing | `ModuleFiles`, `ModuleBuilder::assertValidPhp()` |
| I18 | Generated factories never create `User` records (real, loginable accounts) — they pick an existing user | `ModuleFiles::factory()` |

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

### 10.0 Add a CRUD module the quick way

Use the **Module Builder** (§14) — page or `php artisan filalaunch:make-module`. Then edit the generated files as needed. §10.1 is the manual path.

### 10.1 Add a new CRUD resource with permissions

1. `php artisan make:model Project -m` → add columns → `php artisan migrate`
2. `php artisan make:filament-resource Project --not-embedded --record-title-attribute=name`
3. Add `->authorizeIndividualRecords('delete')` to the table's `DeleteBulkAction` (I10)
4. Done. `projects.*` permissions are created on the next panel request and `DefaultPolicy` guards the model. The Super Admin assigns them to roles/users.
5. Only if the model needs safety rules: `php artisan filalaunch:sync-permissions --policies` scaffolds `app/Policies/ProjectPolicy.php` (extends `ResourcePolicy`); override the method, return `false` for the rule, else `parent::method(...)`
6. Update §3 / §11 of this spec if it's a kit feature

A GUI resource builder needs no extra step: once its resource class is registered in the panel, steps 4–5 apply unchanged. It may call `app(PermissionSync::class)->sync()` right after generating to create permissions immediately.

### 10.2 Add a custom permission

1. Create it in the panel (Permissions → New) or in a seeder via `Permission::firstOrCreate(['name' => 'projects.export', 'guard_name' => 'web'])`
2. Check it with `$user->can('export', Project::class)` (resolved by the policy) or `$user->can('projects.export')`; on a Filament action: `->authorize(fn () => auth()->user()->can('export', Project::class))`

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
| 2 | Automatic permission creation for every resource (panel boot check) | ✅ |
| 2 | Default-deny `DefaultPolicy` + `ResourcePolicy` base + strict authorization | ✅ |
| 3 | Module Builder page + `filalaunch:make-module` (migration, model, factory, seeder, policy, resource, soft deletes, belongs-to + has one/has many) | ✅ |
| — | Next modules | to be defined (see filalaunch.com) |

---

## 12. Working rules for contributors and AI tools

1. **Read before writing.** Check this spec and the files you touch; follow existing patterns.
2. **Surgical changes.** Change only what the task needs; don't rewrite working files or reformat unrelated code.
3. **Respect the invariants (§7)** and the out-of-scope list (§1).
4. **Authorization goes through policies + permissions.** No role-name checks in resources, no Shield. Never add `skipAuthorization()` to a resource or remove `strictAuthorization()`.
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
| 2026-11-07 | Permissions for every resource are created automatically (panel `bootUsing` → `ensureSynced`); only created, never assigned or deleted — assignment is the Super Admin's job |
| 2026-11-07 | Every model guarded by default: `DefaultPolicy` fallback via `Gate::guessPolicyNamesUsing`; custom policies extend `ResourcePolicy` and hold only safety rules; panel uses `strictAuthorization()` |
| 2026-11-07 | Module Builder: one engine for page + CLI; Filament's `make:filament-resource --generate` builds the resource from the new table; local-only by default, `FILALAUNCH_BUILDER_ENABLED` for other environments; inverse relations and seeder registration edit existing files by appending only |
| 2026-11-07 | Default `QUEUE_CONNECTION=sync` so the kit runs on shared hosting without a worker; README documents cron and Supervisor setups |

---

## 14. Module Builder

One engine (`App\Support\ModuleBuilder\ModuleBuilder`) behind the **Developer → Module Builder** page and `php artisan filalaunch:make-module`.

### 14.1 Input (`ModuleDefinition`)

| Part | Rules |
|---|---|
| Model | Normalised to singular StudlyCase. Rejected if reserved (`User`, `Role`, `Permission`, `Model`, PHP keywords…), or if the model, table, migration, resource folder, factory, seeder or policy already exists |
| Fields | snake_case; not `id`, timestamps, `deleted_at` or Eloquent property names. Types: `string text integer decimal boolean date datetime json`. Options: nullable, unique (not text/json/boolean), default (string/integer/decimal/boolean only, type-checked) |
| Belongs-to | Existing `App\Models` class. Adds `{related_snake}_id` (`foreignId()->constrained('<related table>')`) + `belongsTo` method. On delete: cascade / set null (forces nullable) / restrict |
| Inverse | `has_many` → `{camelPlural}()` or `has_one` → `{camelSingular}()` appended to the related model. Rejected if that method already exists |
| Options | soft deletes, factory, seeder (+count, implies factory), run seeder now, policy file |

### 14.2 Build order (`ModuleBuilder::build()`)

1. Validate (`errors()`); abort with the full list.
2. Create migration, model, factory, seeder, policy from `stubs/` — each syntax-checked (`token_get_all(..., TOKEN_PARSE)`).
3. Append inverse relation method (+ import) to related models; register `{Model}Seeder::class` in `DatabaseSeeder` (`$this->call([...])`). Originals backed up.
4. `migrate --path=<this migration> --force`.
5. `make:filament-resource {Model} --generate [--soft-deletes] --not-embedded --panel=admin --record-title-attribute=<name|title|first string>`.
6. Post-process the resource: bulk actions get `authorizeIndividualRecords()` (I10); json fields use `KeyValue` instead of `Textarea`.
7. **Any failure in 1–6 → rollback (I16).**
8. Non-fatal (warnings only): run seeder (skipped with a clear message if a required related model has no factory and no rows), `PermissionSync::syncModel()`, cache refresh.

### 14.3 Production behaviour

Enabled via `FILALAUNCH_BUILDER_ENABLED=true`. After a build:

- `bootstrap/cache/filament` present → `filament:clear-cached-components`
- routes cached → `route:cache` (fresh app, discovers the new resource); falls back to `route:clear` + warning
- `opcache_invalidate()` on every created/edited file
- Migrations/seeders run with `--force`

Requirements: writable `app/`, `database/`, `bootstrap/cache/`; no `--classmap-authoritative` autoloader; generated files must be committed back to the repo.

### 14.4 Extending

- Change generated code style → edit `stubs/module-builder/*.stub` (placeholders `{{ table }}`, `{{ class }}`, `{{ columns }}`, …).
- New field type → `ModuleDefinition::FIELD_TYPES` (+ `DEFAULTABLE_TYPES` if applicable), and the `match` blocks in `ModuleFiles::migration()`, `model()` (casts) and `fakerFor()`.
- Keep page and command in sync: both only build input arrays for `ModuleDefinition::fromArray()`.
