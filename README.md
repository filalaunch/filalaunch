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
- **Policy-based authorization** — every resource is protected by a standard Laravel policy. No extra authorization package.
- **Predictable permission names** — `{resource}.{ability}`, e.g. `users.view_any`, `users.create`, `invoices.delete`.
- **Auto-generate permissions** — `php artisan filalaunch:sync-permissions` creates the standard set for every Filament resource, and can scaffold the policy too.

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

1. The resource calls the model's **policy** (e.g. `UserPolicy@update`).
2. The policy runs any **safety rule** first (e.g. "can't edit a Super Admin") and returns `false` if it fails.
3. Otherwise it returns `$user->can('users.update')`, which is true if **any role or a direct permission** grants it.
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

# 3. Permissions (projects.view_any … projects.delete) + a ready-made ProjectPolicy
php artisan filalaunch:sync-permissions --policies
```

4. In the panel, open **Roles**, tick the new `Projects` permissions for the right roles, and save.

That's it — the menu item, buttons and pages now follow those permissions.

> Prefer clicking? **Access Control → Permissions → Sync from resources** does step 3 (permissions only, not policies).

Custom permission (e.g. `projects.export`)? Create it under **Permissions**, then check it in your code with `$user->can('projects.export')`.

---

## Configuration

All FilaLaunch settings live in `config/filalaunch.php`.

| `.env` key | Default | Purpose |
|---|---|---|
| `SUPERADMIN_NAME` | `Super Admin` | Name of the seeded Super Admin |
| `SUPERADMIN_EMAIL` | *(empty)* | Login email. The seeder skips the Super Admin if empty |
| `SUPERADMIN_PASSWORD` | *(empty)* | Login password. The seeder skips the Super Admin if empty |

| `config/filalaunch.php` key | Default | Purpose |
|---|---|---|
| `roles.super_admin` | `super_admin` | Role that bypasses permission checks |
| `roles.admin` | `admin` | Seeded admin role |
| `roles.default` | `user` | Role given to self-registered users |
| `permissions.abilities` | `view_any, view, create, update, delete` | Abilities generated per resource |
| `permissions.soft_delete_abilities` | `restore, force_delete` | Added when the model uses `SoftDeletes` |

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
| `php artisan filalaunch:sync-permissions` | Create missing permissions for all resources |
| `php artisan filalaunch:sync-permissions --policies` | Same + generate missing policy classes |
| `php artisan permission:cache-reset` | Clear Spatie's permission cache |
| `php artisan queue:work` | Process queued jobs (only when `QUEUE_CONNECTION` isn't `sync`) |
| `php artisan queue:restart` | Restart workers after a deploy |
| `vendor/bin/pint` | Format code (Laravel Pint) |

---

## Project structure

```
app/
├── Console/Commands/SyncPermissionsCommand.php   # filalaunch:sync-permissions
├── Filament/
│   ├── Concerns/SyncsPermissionCheckboxes.php    # saves grouped permission checkboxes
│   ├── Pages/Auth/Register.php                   # assigns default role on sign-up
│   ├── Resources/
│   │   ├── Users/        (Schemas/, Tables/, Pages/, Actions/)
│   │   ├── Roles/        (Schemas/, Tables/, Pages/)
│   │   └── Permissions/  (Schemas/, Tables/, Pages/)
│   └── Support/PermissionCheckboxes.php          # grouped checkbox UI
├── Models/                 User, Role, Permission
├── Policies/               UserPolicy, RolePolicy, PermissionPolicy
├── Providers/
│   ├── AppServiceProvider.php                    # Super Admin gate
│   └── Filament/AdminPanelProvider.php           # panel + auth features
└── Support/Permissions/PermissionSync.php        # permission generator
config/filalaunch.php                             # kit settings
database/seeders/                                 # roles, permissions, Super Admin
docs/BUILD-SPEC.md                                # architecture & conventions
stubs/filalaunch-policy.stub                      # template for generated policies
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
