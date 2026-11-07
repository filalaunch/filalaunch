<?php

namespace App\Support\ModuleBuilder;

use App\Models\Permission;
use App\Models\Role;
use App\Support\ModuleBuilder\Concerns\RefreshesAppCaches;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Recoverable backups of deleted modules in storage/app/filalaunch/trash/{id}/:
 *
 *   manifest.json   what was deleted / edited, migrations, permissions + assignments
 *   files/…         deleted files (original paths)
 *   originals/…     edited files before the edit
 *   data.ndjson.gz  table rows (gzip, one JSON row per line)
 *
 * Entries older than `filalaunch.builder.trash_days` are removed automatically.
 */
class ModuleTrash
{
    use RefreshesAppCaches;

    public function __construct(protected Filesystem $files) {}

    public static function days(): int
    {
        return max(0, (int) config('filalaunch.builder.trash_days', 7));
    }

    public function path(string $id = ''): string
    {
        return storage_path('app/filalaunch/trash'.($id !== '' ? '/'.$id : ''));
    }

    // ── Store ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $plan  from ModuleRemover::inspect()
     * @param  array<string, string>  $backup  absolute path => contents (deleted + edited files)
     * @return string|null trash id, or null when the trash is disabled
     */
    public function store(array $plan, array $backup): ?string
    {
        if (static::days() === 0) {
            return null;
        }

        $id = now()->format('Ymd_His_v').'_'.$plan['name'];
        $dir = $this->path($id);
        $this->files->ensureDirectoryExists($dir);

        $edited = array_keys($plan['edits']);
        $deletedFiles = [];

        foreach ($backup as $path => $contents) {
            $relative = $this->relative($path);
            $target = in_array($path, $edited, true) ? "originals/{$relative}" : "files/{$relative}";

            $this->files->ensureDirectoryExists(dirname("{$dir}/{$target}"));
            $this->files->put("{$dir}/{$target}", $contents);

            if (! in_array($path, $edited, true)) {
                $deletedFiles[] = $relative;
            }
        }

        $rows = 0;
        $data = null;

        if ($plan['table'] && $plan['rows']) {
            $gz = gzopen("{$dir}/data.ndjson.gz", 'wb6');

            foreach (DB::table($plan['table'])->cursor() as $row) {
                gzwrite($gz, json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
                $rows++;
            }

            gzclose($gz);
            $data = 'data.ndjson.gz';
        }

        $manifest = [
            'id' => $id,
            'model' => $plan['model'],
            'name' => $plan['name'],
            'table' => $plan['table'],
            'deleted_at' => now()->toIso8601String(),
            'files' => $deletedFiles,
            'edits' => collect($plan['edits'])->map(fn (array $edit, string $path): array => [
                'path' => $this->relative($path),
                'description' => $edit['description'],
                'after_sha1' => sha1($edit['content']),
            ])->values()->all(),
            'migrations' => $plan['migrations'],
            'permissions' => $this->permissionSnapshot($plan['permissions']),
            'rows' => $rows,
            'data' => $data,
        ];

        $this->files->put("{$dir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $id;
    }

    // ── Read ─────────────────────────────────────────────────────────

    /**
     * @return array<string, array<string, mixed>> newest first, keyed by id
     */
    public function entries(): array
    {
        $entries = [];

        if (! $this->files->isDirectory($this->path())) {
            return [];
        }

        foreach ($this->files->directories($this->path()) as $dir) {
            $manifest = $this->manifest(basename($dir));

            if ($manifest === null) {
                continue;
            }

            $deletedAt = Carbon::parse($manifest['deleted_at']);
            $bytes = collect($this->files->allFiles($dir))->sum(fn ($file): int => $file->getSize());

            $entries[$manifest['id']] = [
                ...$manifest,
                'key' => $manifest['id'],
                'file_count' => count($manifest['files']),
                'size' => Number::fileSize($bytes, precision: 1),
                'deleted_ago' => $deletedAt->diffForHumans(),
                'expires' => static::days() > 0 ? $deletedAt->copy()->addDays(static::days())->toFormattedDateString() : '—',
            ];
        }

        krsort($entries);

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(string $id): ?array
    {
        if (! $this->validId($id) || ! $this->files->exists($this->path($id).'/manifest.json')) {
            return null;
        }

        return json_decode($this->files->get($this->path($id).'/manifest.json'), true) ?: null;
    }

    /**
     * Reasons a restore can't run right now (empty = OK).
     *
     * @return list<string>
     */
    public function conflicts(string $id): array
    {
        $manifest = $this->manifest($id);

        if ($manifest === null) {
            return ['Trash entry not found.'];
        }

        $conflicts = [];

        foreach ($manifest['files'] as $relative) {
            if ($this->files->exists(base_path($relative))) {
                $conflicts[] = "{$relative} exists again.";
            }
        }

        foreach ($manifest['edits'] as $edit) {
            $path = base_path($edit['path']);

            if (! $this->files->exists($path) || sha1($this->files->get($path)) !== $edit['after_sha1']) {
                $conflicts[] = "{$edit['path']} changed after the delete — restore it by hand from the trash folder.";
            }
        }

        if ($manifest['table'] && rescue(fn () => Schema::hasTable($manifest['table']), false, report: false)) {
            $conflicts[] = "Table {$manifest['table']} exists again.";
        }

        // Parent tables (foreign keys) must exist first — e.g. restore Category before Post.
        foreach ($manifest['migrations'] as $migration) {
            $file = $this->path($id)."/files/database/migrations/{$migration}.php";

            if (! $this->files->exists($file)) {
                continue;
            }

            preg_match_all("/constrained\\(\\s*['\"]([^'\"]+)['\"]/", $this->files->get($file), $matches);

            foreach (array_unique($matches[1]) as $parent) {
                if (! rescue(fn () => Schema::hasTable($parent), false, report: false)) {
                    $conflicts[] = "Table {$parent} doesn't exist — restore that module first.";
                }
            }
        }

        return $conflicts;
    }

    // ── Restore ──────────────────────────────────────────────────────

    /**
     * @return list<string> warnings
     *
     * @throws ModuleBuilderException
     */
    public function restore(string $id): array
    {
        if (! ModuleBuilder::enabled()) {
            throw ModuleBuilderException::invalid(['Module Builder is disabled. Set FILALAUNCH_BUILDER_ENABLED=true in .env to enable it.']);
        }

        if ($conflicts = $this->conflicts($id)) {
            throw ModuleBuilderException::invalid($conflicts);
        }

        $manifest = $this->manifest($id);
        $dir = $this->path($id);
        $written = [];
        $editsAfter = [];
        $tableCreated = false;
        $warnings = [];

        try {
            foreach ($manifest['files'] as $relative) {
                $this->files->ensureDirectoryExists(dirname(base_path($relative)));
                $this->files->copy("{$dir}/files/{$relative}", base_path($relative));
                $written[] = base_path($relative);
            }

            foreach ($manifest['edits'] as $edit) {
                $path = base_path($edit['path']);
                $editsAfter[$path] = $this->files->get($path);
                $this->files->copy("{$dir}/originals/{$edit['path']}", $path);
                $this->invalidateOpcache($path);
            }

            $migrationPaths = collect($manifest['migrations'])
                ->map(fn (string $name): string => "database/migrations/{$name}.php")
                ->filter(fn (string $relative): bool => $this->files->exists(base_path($relative)))
                ->values()
                ->all();

            if ($migrationPaths !== []) {
                Artisan::call('migrate', ['--path' => $migrationPaths, '--force' => true]);
                $tableCreated = $manifest['table'] && Schema::hasTable($manifest['table']);
            }

            if ($manifest['data']) {
                if (! $tableCreated) {
                    $warnings[] = "Table {$manifest['table']} wasn't recreated (no migration), so its rows were not restored. They're still in {$this->relative($dir)}/{$manifest['data']}.";
                } else {
                    $this->restoreRows($manifest['table'], "{$dir}/{$manifest['data']}");
                }
            }

            $this->restorePermissions($manifest['permissions']);
        } catch (Throwable $e) {
            foreach ($written as $path) {
                $this->files->delete($path);
            }

            foreach ($editsAfter as $path => $contents) {
                $this->files->put($path, $contents);
                $this->invalidateOpcache($path);
            }

            if ($tableCreated) {
                rescue(function () use ($manifest): void {
                    Schema::dropIfExists($manifest['table']);
                    DB::table(config('database.migrations.table', 'migrations'))->whereIn('migration', $manifest['migrations'])->delete();
                }, report: false);
            }

            throw ModuleBuilderException::failed($e);
        }

        $warnings = [...$warnings, ...$this->refreshAppCaches($written)];

        // Restored data is warnings-only from here; if the files were restored but data had an issue, keep the entry.
        if (! array_filter($warnings, fn (string $w): bool => str_contains($w, 'rows were not restored'))) {
            $this->purge($id);
        }

        return $warnings;
    }

    // ── Clean up ─────────────────────────────────────────────────────

    public function purge(string $id): void
    {
        if ($this->validId($id) && $this->files->isDirectory($this->path($id))) {
            $this->files->deleteDirectory($this->path($id));
        }
    }

    public function purgeAll(): int
    {
        $count = count($this->entries());
        $this->files->deleteDirectory($this->path(), preserve: true);

        return $count;
    }

    /**
     * Remove entries older than trash_days.
     */
    public function prune(): int
    {
        $pruned = 0;

        foreach ($this->entries() as $entry) {
            if (static::days() === 0 || Carbon::parse($entry['deleted_at'])->addDays(static::days())->isPast()) {
                $this->purge($entry['id']);
                $pruned++;
            }
        }

        return $pruned;
    }

    // ── Internals ────────────────────────────────────────────────────

    protected function restoreRows(string $table, string $file): void
    {
        $gz = gzopen($file, 'rb');
        $chunk = [];

        DB::transaction(function () use ($gz, $table, &$chunk): void {
            while (($line = gzgets($gz)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                $chunk[] = json_decode($line, true);

                if (count($chunk) === 500) {
                    DB::table($table)->insert($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                DB::table($table)->insert($chunk);
            }
        });

        gzclose($gz);
    }

    /**
     * @param  list<string>  $names
     * @return list<array{name: string, roles: list<string>, users: list<int|string>, plans: list<int>}>
     */
    protected function permissionSnapshot(array $names): array
    {
        $userClass = config('auth.providers.users.model');
        $morph = (new $userClass)->getMorphClass();
        $pivot = config('permission.table_names.model_has_permissions', 'model_has_permissions');
        $key = config('permission.column_names.model_morph_key', 'model_id');

        return Permission::query()->whereIn('name', $names)->with('roles')->get()
            ->map(fn (Permission $permission): array => [
                'name' => $permission->name,
                'roles' => $permission->roles->pluck('name')->all(),
                'users' => DB::table($pivot)->where('permission_id', $permission->id)->where('model_type', $morph)->pluck($key)->all(),
                'plans' => Schema::hasTable('plan_permission') ? DB::table('plan_permission')->where('permission_id', $permission->id)->pluck('plan_id')->all() : [],
            ])
            ->all();
    }

    /**
     * @param  list<array{name: string, roles: list<string>, users: list<int|string>}>  $snapshot
     */
    protected function restorePermissions(array $snapshot): void
    {
        $userClass = config('auth.providers.users.model');

        foreach ($snapshot as $item) {
            $permission = Permission::firstOrCreate(['name' => $item['name'], 'guard_name' => 'web']);

            foreach ($item['roles'] as $role) {
                Role::query()->where('name', $role)->first()?->givePermissionTo($permission);
            }

            foreach ($item['users'] as $userId) {
                $userClass::query()->find($userId)?->givePermissionTo($permission);
            }

            foreach ($item['plans'] ?? [] as $planId) {
                if (Schema::hasTable('plans') && DB::table('plans')->where('id', $planId)->exists()) {
                    DB::table('plan_permission')->insertOrIgnore(['plan_id' => $planId, 'permission_id' => $permission->id]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function validId(string $id): bool
    {
        return (bool) preg_match('/^\d{8}_\d{6}_\d{3}_[A-Za-z0-9]+$/', $id);
    }

    protected function relative(string $path): string
    {
        return Str::after(str_replace('\\', '/', $path), str_replace('\\', '/', base_path()).'/');
    }
}
