<p align="center">
  <a href="https://filalaunch.com">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="art/filalaunch-icon-paper.png">
      <img src="art/filalaunch-icon-ink.png" width="120" alt="FilaLaunch logo">
    </picture>
  </a>
</p>

<h1 align="center">FilaLaunch</h1>

<p align="center">
  A SaaS starter kit built on <strong>Laravel 12</strong> and <strong>FilamentPHP 4</strong>.<br>
  Auth, roles and permissions ready on day one — so you start on your product, not the plumbing.
</p>

<p align="center">
  <a href="https://filalaunch.com"><strong>Website</strong></a> ·
  <a href="#quick-start">Quick start</a> ·
  <a href="#features">Features</a> ·
  <a href="docs/BUILD-SPEC.md">Build spec</a> ·
  <a href="https://github.com/filalaunch/filalaunch/issues">Issues</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white" alt="Laravel 12">
  <img src="https://img.shields.io/badge/Filament-4-E1672E" alt="Filament 4">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/license-MIT-172242" alt="MIT License">
</p>

---

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [First login](#first-login)
- [Roles & permissions](#roles--permissions)
- [Adding your own resources](#adding-your-own-resources)
- [Module Builder](#module-builder)
- [Deleting modules](#deleting-modules)
- [Queues](#queues)
- [Configuration](#configuration)
- [Useful commands](#useful-commands)
- [Project structure](#project-structure)
- [Working with AI coding tools](#working-with-ai-coding-tools)
- [Roadmap](#roadmap)
- [License](#license)

---

## Features

Everything below works out of the box after `migrate --seed`.

### Authentication (Filament admin panel at `/admin`)

| Feature | Details |
|---|---|
| Login | Email + password, rate-limited |
| Registration | Open sign-up; new users get the default `user` role automatically |
| Email verification | Required before entering the panel (signed link) |
| Password reset | Request link by email, set a new password |
| Profile page | Update name, email and password inside the panel |
| Two-factor auth (2FA) | Optional, per user. Authenticator app (Google Authenticator, 1Password, Authy…) with recovery codes |

### Access control (`Access Control` menu)

| Screen | What you can do |
|---|---|
| **Users** | Create, edit, delete users · assign roles · grant direct permissions · mark email verified · reset a user's 2FA · filter by role / verification |
| **Roles** | Create, edit, delete roles · pick permissions from checkboxes grouped by resource |
| **Permissions** | Create, edit, delete permissions · grouped by resource · one-click **Sync from resources** |

- **Permissions at two levels** — attach them to a role, directly to a user, or both. A user's effective permissions are the union.
- **Secure by default** — every model is guarded by a policy automatically. No permission = no access; a new resource stays hidden until the Super Admin grants it. No extra authorization package.
- **Predictable permission names** — `{resource}.{ability}`, e.g. `users.view_any`, `users.create`, `invoices.delete`.
- **Permissions create themselves** — add a Filament resource and its `view_any / view / create / update / delete` permissions appear on the next panel visit. Nothing to run, nothing assigned automatically.

### Module Builder (`Developer` menu, Super Admin only)

Generate a complete CRUD module from a form or one command — **migration, model, factory, seeder with fake data, policy and Filament resource**:

- Field types: string, text, integer, decimal, boolean, date, datetime, json — with nullable, unique and default options
- Optional **soft deletes** (trash filter, restore and force delete, plus `restore` / `force_delete` permissions)
- **Belongs-to** relationships, with optional **has many / has one** added to the related model
- Choose the **menu icon, navigation group and sort order** for the new resource
- Preview before generating; automatic rollback if anything fails; the panel reloads so the new menu item appears instantly
- Works in production too when enabled (rebuilds route cache, clears component cache)

### Modules page (`Developer` menu, Super Admin only)

- Lists **every resource** — builder-made or hand-written — except Users, Roles and Permissions
- **Delete** a module completely: resource, model, factory, seeder, policy, migrations, relationship methods in other models, `DatabaseSeeder` line, table and permissions
- **Delete all** in one go (dependents first)
- **Trash with one-click restore** — files, edits, table rows and permission assignments; auto-cleaned after 7 days

### Built-in safety rules

- Nobody can delete their own account from the Users screen.
- The last Super Admin can never be deleted.
- Only a Super Admin can edit another Super Admin or assign the `super_admin` role.
- Users can't change their own roles or direct permissions (no self-escalation, no lock-out).
- The `super_admin` and `user` roles can't be deleted; `super_admin` can't be edited.

### Developer experience

- Super Admin account created from `.env` — no tinker, no manual SQL.
- SQLite by default — clone and run, no database server needed.
- Clean Filament v4 structure: separate `Schemas/`, `Tables/` and `Pages/` per resource.
- [`docs/BUILD-SPEC.md`](docs/BUILD-SPEC.md) documents the architecture and conventions, so you (or an AI coding tool) can extend the kit without breaking it.

---

## Requirements

- PHP **8.2+** with the `sqlite3`, `pdo_sqlite`, `mbstring`, `openssl`, `intl` and `zip` extensions
- Composer **2.x**
- Git
- Node.js 20+ *(optional — only needed for the public welcome page assets; the admin panel works without it)*

Using MySQL or PostgreSQL instead of SQLite? Supported — see [Configuration](#configuration).

---

## Quick start

```bash
# 1. Clone
git clone https://github.com/filalaunch/filalaunch.git
cd filalaunch

# 2. Install PHP dependencies (also publishes Filament assets)
composer install

# 3. Environment file + app key
cp .env.example .env
php artisan key:generate

# 4. Create the SQLite database file
touch database/database.sqlite
```

**5. Set your Super Admin credentials** in `.env`:

```dotenv
SUPERADMIN_NAME="Your Name"
SUPERADMIN_EMAIL=you@example.com
SUPERADMIN_PASSWORD=choose-a-strong-password
```

```bash
# 6. Create tables, roles, permissions and the Super Admin
php artisan migrate --seed

# 7. Run the app
php artisan serve
```

> Have Node.js? `composer dev` runs the server, a queue worker, a log tail (Pail) and Vite together.

Open **http://127.0.0.1:8000/admin** and sign in with the Super Admin credentials from step 5.

> **Windows:** replace `cp` with `copy` and `touch database/database.sqlite` with `type nul > database\database.sqlite`.
> **Laravel Herd / Valet:** set `APP_URL` in `.env` to your site URL (e.g. `https://filalaunch.test`).

### Starting over

```bash
php artisan migrate:fresh --seed
```

Drops every table and re-seeds roles, permissions and the Super Admin.

---

## First login

1. Sign in at `/admin` as the Super Admin.
2. Open **Profile** (avatar menu, top right) → **Two-factor authentication** → **Set up** to enable 2FA. *(Optional, recommended.)*
3. Go to **Access Control → Roles** and check the seeded roles.
4. Try registering a second account at `/admin/register` in a private window.

### Emails in local development

The default mail driver is `log`, so verification and password-reset emails are written to:

```
storage/logs/laravel.log
```

To send real emails, set `MAIL_MAILER` and the related `MAIL_*` values in `.env`.

---

## Queues

Verification and password-reset emails are queued notifications. FilaLaunch ships with `QUEUE_CONNECTION=sync`, which runs them **immediately inside the request** — no worker needed, works on any host including shared hosting.

| Setup | `.env` | Worker | Best for |
|---|---|---|---|
| **Sync** *(default)* | `QUEUE_CONNECTION=sync` | none | Local dev, shared hosting, low traffic |
| **Database + cron** | `QUEUE_CONNECTION=database` | cron job, every minute | Shared hosting with cron (cPanel, Hostinger…) |
| **Database / Redis + Supervisor** | `QUEUE_CONNECTION=database` or `redis` | always-on process | VPS (Forge, Ploi, plain Ubuntu), heavier jobs |

> With `sync`, a request that sends an email waits for the mail server (usually 1–2 s), and a mail failure shows an error instead of retrying later. Switch to a real queue when that matters.

### Shared hosting with cron

Set `QUEUE_CONNECTION=database`, then add this cron job (replace the path):

```
* * * * * cd /home/username/filalaunch && php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

Every minute it sends whatever is waiting and exits. Emails arrive within about a minute; failed jobs are retried.

### VPS with Supervisor

Set `QUEUE_CONNECTION=database` (or `redis`) and keep a worker running with Supervisor:

```ini
[program:filalaunch-worker]
command=php /var/www/filalaunch/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
stopwaitsecs=3600
stdout_logfile=/var/www/filalaunch/storage/logs/worker.log
```

After each deploy, run `php artisan queue:restart` so workers pick up new code.

---

## Roles & permissions

### Seeded roles

| Role | Purpose | Default permissions |
|---|---|---|
| `super_admin` | Full access. Bypasses all permission checks (safety rules still apply). | — (implicit) |
| `admin` | Day-to-day user management | `users.*`, `roles.view_any`, `roles.view`, `permissions.view_any`, `permissions.view` |
| `user` | Assigned to everyone who self-registers | none — dashboard and profile only |

Change any of these from the panel at any time (except the protected rules above).

### Permission naming

```
{resource}.{ability}
```

| Ability | Policy method | Used for |
|---|---|---|
| `view_any` | `viewAny` | List page + menu item |
| `view` | `view` | View a record |
| `create` | `create` | Create button / page |
| `update` | `update` | Edit button / page |
| `delete` | `delete`, `deleteAny` | Delete + bulk delete |
| `restore` | `restore`, `restoreAny` | Only for models using `SoftDeletes` |
| `force_delete` | `forceDelete`, `forceDeleteAny` | Only for models using `SoftDeletes` |

The resource prefix is the model name in plural snake case: `User → users`, `BlogPost → blog_posts`.

### How a check is decided

1. The resource calls the model's **policy** — its own `App\Policies\{Model}Policy` if one exists, otherwise the built-in `DefaultPolicy`.
2. A custom policy runs its **safety rules** first (e.g. "can't edit a Super Admin") and returns `false` if one fails.
3. Otherwise the check becomes `$user->can('users.update')`, which is true if **any role or a direct permission** grants it. No permission → denied.
4. A **Super Admin** passes every check that a safety rule didn't explicitly deny.

---

## Adding your own resources

Example: a `Project` model.

```bash
# 1. Model + migration
php artisan make:model Project -m
#    ...add columns, then:
php artisan migrate

# 2. Filament resource
php artisan make:filament-resource Project
```

3. Open the panel. `projects.view_any`, `projects.view`, `projects.create`, `projects.update` and `projects.delete` are created automatically. Until you grant them, only the Super Admin can see Projects.
4. Open **Roles** (or a **User**), tick the `Projects` permissions you want, and save.

That's it — no policy file, no command. The menu item, buttons and pages follow those permissions.

**Need extra rules?** (e.g. "locked projects can't be deleted") Scaffold a policy and override only that method:

```bash
php artisan filalaunch:sync-permissions --policies   # creates app/Policies/ProjectPolicy.php
```

**Custom permission** (e.g. `projects.export`)? Create it under **Permissions**, then check it with `$user->can('export', Project::class)` or `$user->can('projects.export')`.

> Permissions are only ever **created** automatically — never assigned and never deleted. If you remove a resource, delete its leftover permissions from the Permissions page.

---

## Module Builder

Generates everything a new CRUD module needs, in one step. Same engine for the panel page and the command.

### From the panel

**Developer → Module Builder** (Super Admin only). Enter a model name, pick the menu icon, navigation group (existing or new) and sort order, add fields, optional belongs-to relationships and options, click **Generate module**, review the plan, confirm. You're taken to the dashboard with the new resource already in the sidebar.

### From the command line

```bash
# Interactive — asks for everything
php artisan filalaunch:make-module

# One line
php artisan filalaunch:make-module Post \
  --fields="title:string,body:text:nullable,price:decimal:default=0,email:string:unique" \
  --belongs-to=Category:has_many \
  --belongs-to=User:has_one:nullable \
  --soft-deletes --seeder=20 \
  --icon=newspaper --group=Content --sort=1
```

| Option | Meaning |
|---|---|
| `--fields` | `name:type[:nullable][:unique][:default=value]`, comma-separated. Types: `string text integer decimal boolean date datetime json` |
| `--belongs-to` | `Model[:has_many\|has_one\|none][:nullable]` — repeatable. Nullable relations use *set null* on delete, others *cascade* |
| `--soft-deletes` | Trash, restore, force delete |
| `--factory` | Create a factory |
| `--seeder=N` | Seeder with N fake records (implies `--factory`), registered in `DatabaseSeeder` and run now |
| `--no-seed` | Create the seeder but don't run it |
| `--policy` | Scaffold a policy for custom rules (not needed for permission checks) |
| `--icon` | Menu icon — any [Heroicon](https://heroicons.com) name, e.g. `newspaper`, `shopping-cart` (outlined style) |
| `--group` | Navigation group — existing or new, e.g. `Content` |
| `--sort` | Position within the group (lower first) |

### What gets generated (example: `Post`)

| File / action | Notes |
|---|---|
| `database/migrations/…_create_posts_table.php` | Columns, foreign keys (`constrained()`), `softDeletes()` |
| `app/Models/Post.php` | `$fillable`, casts, `HasFactory`, `SoftDeletes`, `belongsTo` methods |
| `app/Models/Category.php` *(updated)* | `posts()` has-many method appended — nothing else touched |
| `database/factories/PostFactory.php` | Fake values by field type and name (`email`, `phone`, `title`…) |
| `database/seeders/PostSeeder.php` | Added to `DatabaseSeeder`, so `migrate:fresh --seed` recreates the data |
| `app/Filament/Resources/Posts/` | Form, table, pages — generated from the new table (relationship selects, trashed filter) |
| Migration run, seeder run | Only this module's migration |
| `posts.*` permissions | Created, **not assigned** — grant them under Access Control |

If any step fails, everything is rolled back: new files deleted, edited files restored, table dropped.

Generated code is a starting point — edit it like any other code. Templates live in `stubs/module-builder/` if you want to change the defaults.

### Enabling outside local

The builder is **on only when `APP_ENV=local`**. To use it on a server, add to `.env`:

```dotenv
FILALAUNCH_BUILDER_ENABLED=true
```

It works with `php artisan optimize` caches on — after generating, it rebuilds the route cache, clears Filament's component cache and refreshes OPcache for edited files. Requirements on a server:

- The web server user can write to `app/`, `database/` and `bootstrap/cache/`.
- Composer autoloader is **not** built with `--classmap-authoritative` (plain `--optimize-autoloader` is fine), so new classes load without `composer dump-autoload`.
- Commit the generated files back to your repository, or the next deploy will remove them.

---

## Deleting modules

**Developer → Modules** lists every resource in the panel (builder-made or hand-written). Users, Roles and Permissions are protected and never listed.

Click **Delete** to see exactly what will be removed, then confirm:

| Removed | Example (`Post`) |
|---|---|
| Resource folder | `app/Filament/Resources/Posts/` |
| Model, factory, seeder, policy | `app/Models/Post.php`, `PostFactory`, `PostSeeder`, `PostPolicy` |
| Migrations for its table | `…_create_posts_table.php` |
| Relationship methods in other models | `posts()` in `Category.php` and `User.php` (+ unused imports) |
| Seeder registration | `PostSeeder::class` in `DatabaseSeeder` |
| Table + migration record | `posts` |
| Permissions + their role/user assignments | `posts.*` |

**Delete all** (type `DELETE ALL` to confirm) removes every listed module, dependents first.

### Safety

- **Blocked when something depends on it** — e.g. *Category* can't be deleted while *Post* belongs to it (foreign key, `belongsTo`, or any other code still using the class). Delete the dependent first, or use **Delete all**.
- Migrations that also change other tables are never deleted — the module is blocked until you split them.
- All-or-nothing: if any step fails, every file is put back.

### Trash & restore

Each deleted module is backed up to `storage/app/filalaunch/trash/` (not committed): files, the original versions of edited files, table rows (gzip) and permission assignments.

- **Restore** from the Trash list under the Modules page — everything returns as it was. Restore parents before children (Category before Post); the page tells you if the order is wrong or a file changed since.
- Backups are removed automatically after `FILALAUNCH_TRASH_DAYS` days (default 7). **Delete forever** / **Empty trash** free space immediately. `FILALAUNCH_TRASH_DAYS=0` disables the trash (deletes are permanent).

### From the command line

```bash
php artisan filalaunch:delete-module Post            # preview + confirm
php artisan filalaunch:delete-module --all           # type DELETE ALL to confirm
php artisan filalaunch:delete-module Post --force -n # no prompt (scripts)

php artisan filalaunch:module-trash                  # list backups
php artisan filalaunch:module-trash restore <id>
php artisan filalaunch:module-trash purge <id>
php artisan filalaunch:module-trash empty
```

> **Tip:** keep experiments out of your repository — test the Module Builder in a separate clone, and commit only the modules you want to ship.

---

## Configuration

All FilaLaunch settings live in `config/filalaunch.php`.

| `.env` key | Default | Purpose |
|---|---|---|
| `SUPERADMIN_NAME` | `Super Admin` | Name of the seeded Super Admin |
| `SUPERADMIN_EMAIL` | *(empty)* | Login email. The seeder skips the Super Admin if empty |
| `SUPERADMIN_PASSWORD` | *(empty)* | Login password. The seeder skips the Super Admin if empty |
| `FILALAUNCH_BUILDER_ENABLED` | `true` only when `APP_ENV=local` | Turns the Module Builder and Modules pages and commands on/off |
| `FILALAUNCH_TRASH_DAYS` | `7` | Days deleted modules stay in the trash (`0` = no trash) |

| `config/filalaunch.php` key | Default | Purpose |
|---|---|---|
| `roles.super_admin` | `super_admin` | Role that bypasses permission checks |
| `roles.admin` | `admin` | Seeded admin role |
| `roles.default` | `user` | Role given to self-registered users |
| `permissions.abilities` | `view_any, view, create, update, delete` | Abilities generated per resource |
| `permissions.soft_delete_abilities` | `restore, force_delete` | Added when the model uses `SoftDeletes` |
| `builder.enabled` | from `FILALAUNCH_BUILDER_ENABLED` | Module Builder / Modules on/off |
| `builder.protected_models` | `[]` | Extra models the Modules page never lists or deletes (User, Role, Permission always protected) |
| `builder.trash_days` | from `FILALAUNCH_TRASH_DAYS` | Trash retention |

> The seeder never overwrites an existing Super Admin's password. To change it, use the Profile page or run `migrate:fresh --seed`.

### Using MySQL / PostgreSQL

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=filalaunch
DB_USERNAME=root
DB_PASSWORD=
```

Then run `php artisan migrate --seed`.

---

## Useful commands

| Command | What it does |
|---|---|
| `composer dev` | Runs server, queue worker, log tail (Pail) and Vite together |
| `php artisan migrate --seed` | Create tables + seed roles, permissions, Super Admin |
| `php artisan migrate:fresh --seed` | Reset everything |
| `php artisan db:seed --class=SuperAdminSeeder` | (Re)create the Super Admin from `.env` |
| `php artisan filalaunch:sync-permissions` | Create missing permissions now (also happens automatically) |
| `php artisan filalaunch:sync-permissions --policies` | Same + scaffold policy classes for adding extra rules |
| `php artisan filalaunch:make-module` | Generate a module (migration, model, factory, seeder, resource) |
| `php artisan filalaunch:delete-module` | Delete a module (or `--all`), backed up to the trash |
| `php artisan filalaunch:module-trash` | List / restore / purge deleted modules |
| `php artisan permission:cache-reset` | Clear Spatie's permission cache |
| `php artisan queue:work` | Process queued jobs (only when `QUEUE_CONNECTION` isn't `sync`) |
| `php artisan queue:restart` | Restart workers after a deploy |
| `vendor/bin/pint` | Format code (Laravel Pint) |

---

## Project structure

```
app/
├── Console/Commands/
│   ├── SyncPermissionsCommand.php                # filalaunch:sync-permissions
│   ├── MakeModuleCommand.php                     # filalaunch:make-module
│   ├── DeleteModuleCommand.php                   # filalaunch:delete-module
│   └── ModuleTrashCommand.php                    # filalaunch:module-trash
├── Filament/
│   ├── Concerns/SyncsPermissionCheckboxes.php    # saves grouped permission checkboxes
│   ├── Pages/Auth/Register.php                   # assigns default role on sign-up
│   ├── Pages/ModuleBuilder.php                   # Module Builder page
│   ├── Pages/Modules.php                         # Modules page (list, delete)
│   ├── Widgets/ModuleTrashWidget.php             # Trash list + restore (on Modules page)
│   ├── Resources/
│   │   ├── Users/        (Schemas/, Tables/, Pages/, Actions/)
│   │   ├── Roles/        (Schemas/, Tables/, Pages/)
│   │   └── Permissions/  (Schemas/, Tables/, Pages/)
│   └── Support/PermissionCheckboxes.php          # grouped checkbox UI
├── Models/                 User, Role, Permission
├── Policies/               ResourcePolicy (base), DefaultPolicy (fallback),
│                           UserPolicy, RolePolicy, PermissionPolicy
├── Providers/
│   ├── AppServiceProvider.php                    # Super Admin gate + default policy
│   └── Filament/AdminPanelProvider.php           # panel + auth features
└── Support/
    ├── ModuleBuilder/                            # Module Builder, remover, trash
    └── Permissions/PermissionSync.php            # permission generator
config/filalaunch.php                             # kit settings
database/seeders/                                 # roles, permissions, Super Admin
docs/BUILD-SPEC.md                                # architecture & conventions
stubs/filalaunch-policy.stub                      # template for generated policies
stubs/module-builder/                             # templates for generated modules
```

---

## Working with AI coding tools

FilaLaunch ships with a build spec written for both humans and AI assistants:

- [`docs/BUILD-SPEC.md`](docs/BUILD-SPEC.md) — architecture, conventions, invariants and step-by-step recipes.
- [`CLAUDE.md`](CLAUDE.md) — loaded automatically by Claude Code and points to the spec.

Using Cursor, Copilot or another tool? Add `docs/BUILD-SPEC.md` to its context before asking for changes. Keep the spec updated when you change the architecture.

---

## Roadmap

FilaLaunch is built in small, tested steps.

- [x] **Step 1** — Admin panel auth (login, registration, email verification, password reset, profile, 2FA) + dynamic users, roles & permissions
- [x] **Step 2** — Automatic permissions for every resource + secure-by-default policies (no permission = no access)
- [x] **Step 3** — Module Builder: generate migration, model, factory, seeder and resource from the panel or CLI
- [x] **Step 4** — Modules page: delete any module completely, with trash and one-click restore
- [ ] More modules — follow progress and release notes at **[filalaunch.com](https://filalaunch.com)**

---

## Contributing

Issues and pull requests are welcome. Please:

1. Read [`docs/BUILD-SPEC.md`](docs/BUILD-SPEC.md) first.
2. Keep changes focused and follow the existing structure.
3. Run `vendor/bin/pint` before committing.

## Security

Found a vulnerability? Please don't open a public issue — email the maintainer via [filalaunch.com](https://filalaunch.com).

## License

FilaLaunch is open-source software licensed under the [MIT license](LICENSE).

---

<p align="center">
  <a href="https://filalaunch.com">filalaunch.com</a> · Built with <a href="https://laravel.com">Laravel</a> and <a href="https://filamentphp.com">FilamentPHP</a>
</p>
