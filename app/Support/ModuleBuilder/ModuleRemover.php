<?php

namespace App\Support\ModuleBuilder;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleBuilder\Concerns\RefreshesAppCaches;
use App\Support\Permissions\PermissionSync;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ParseError;
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Lists every deletable resource (builder-made or hand-written) and deletes a module
 * by convention: resource folder, model, factory, seeder, policy, migrations,
 * relationship methods in other models, DatabaseSeeder line, table, permissions.
 *
 * Safety: protected models, dependency blockers, leftover-reference blockers,
 * preview, trash backup (ModuleTrash), all-or-nothing rollback.
 */
class ModuleRemover
{
    use RefreshesAppCaches;

    /** Relations that point *to* the deleted model from another model and can be removed safely. */
    protected const REMOVABLE_RELATIONS = ['hasMany', 'hasOne'];

    /** Relations meaning another model depends on the deleted one — must be handled first. */
    protected const BLOCKING_RELATIONS = ['belongsTo', 'belongsToMany', 'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany', 'hasManyThrough', 'hasOneThrough'];

    public function __construct(
        protected Filesystem $files,
        protected ModuleTrash $trash,
    ) {}

    /**
     * @return list<class-string<Model>>
     */
    public static function protectedModels(): array
    {
        return array_values(array_unique([
            config('auth.providers.users.model', User::class),
            User::class,
            Role::class,
            Permission::class,
            ...config('filalaunch.builder.protected_models', []),
        ]));
    }

    /**
     * Every deletable module in the admin panel, keyed by model short name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function modules(): array
    {
        $modules = [];

        foreach ($this->resourcesByModel() as $model => $resources) {
            if (in_array($model, static::protectedModels(), true)) {
                continue;
            }

            $plan = $this->inspect($model);

            $modules[class_basename($model)] = [
                'key' => class_basename($model),
                'model' => $model,
                'name' => class_basename($model),
                'table' => $plan['table'],
                'rows' => $plan['rows'],
                'resource' => $this->relative($plan['resource_paths'][0] ?? ''),
                'files' => count($plan['delete']),
                'edits' => count($plan['edits']),
                'blockers' => $plan['blockers'],
                'status' => $plan['blockers'] === [] ? 'Ready' : 'Blocked',
            ];
        }

        ksort($modules);

        return $modules;
    }

    /**
     * Work out everything a delete would do — without changing anything.
     *
     * @return array{model: string, name: string, table: ?string, rows: ?int, resources: list<string>, resource_paths: list<string>, delete: list<string>, edits: array<string, array{description: string, content: string}>, migrations: list<string>, permissions: list<string>, blockers: list<string>, warnings: list<string>}
     */
    public function inspect(string $model): array
    {
        $name = class_basename($model);
        $plan = [
            'model' => $model, 'name' => $name, 'table' => null, 'rows' => null,
            'resources' => [], 'resource_paths' => [], 'delete' => [], 'edits' => [],
            'migrations' => [], 'permissions' => [], 'blockers' => [], 'warnings' => [],
        ];

        if (in_array($model, static::protectedModels(), true)) {
            $plan['blockers'][] = "{$name} is a protected model.";

            return $plan;
        }

        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            $plan['blockers'][] = "Model {$model} doesn't exist.";

            return $plan;
        }

        // ── Model file ──
        $modelFile = (new ReflectionClass($model))->getFileName();

        if ($modelFile && ! is_file($modelFile)) {
            $plan['blockers'][] = "{$name} was already deleted.";

            return $plan;
        }

        if (! $modelFile || ! str_starts_with($this->normalise($modelFile), $this->normalise(app_path('Models')).'/')) {
            $plan['blockers'][] = "{$name} isn't in app/Models — delete it manually.";

            return $plan;
        }

        $plan['delete'][] = $modelFile;

        // ── Filament resources ──
        foreach ($this->resourcesByModel()[$model] ?? [] as $resource) {
            $plan['resources'][] = $resource;

            foreach ($this->resourcePaths($resource) as $path) {
                $plan['resource_paths'][] = $path;
                $plan['delete'][] = $path;
            }
        }

        // ── Factory, seeder, policy ──
        foreach ([
            database_path("factories/{$name}Factory.php"),
            database_path("seeders/{$name}Seeder.php"),
            app_path("Policies/{$name}Policy.php"),
        ] as $path) {
            if ($this->files->exists($path)) {
                $plan['delete'][] = $path;
            }
        }

        // ── Table ──
        $table = (new $model)->getTable();
        $plan['table'] = $table;

        try {
            if (Schema::hasTable($table)) {
                $plan['rows'] = DB::table($table)->count();

                foreach (Schema::getTableListing(schemaQualified: false) as $other) {
                    if ($other === $table) {
                        continue;
                    }

                    foreach (Schema::getForeignKeys($other) as $foreignKey) {
                        if (($foreignKey['foreign_table'] ?? null) === $table) {
                            $plan['blockers'][] = "Table {$other} has a foreign key to {$table} (".implode(', ', $foreignKey['columns']).') — delete that module first.';
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $plan['warnings'][] = "Couldn't inspect the database: {$e->getMessage()}";
        }

        // ── Migrations ──
        foreach ($this->files->glob(database_path('migrations/*.php')) as $migration) {
            preg_match_all('/Schema::(?:create|table)\(\s*[\'"]([^\'"]+)[\'"]/', $this->files->get($migration), $matches);
            $tables = array_unique($matches[1]);

            if (! in_array($table, $tables, true)) {
                continue;
            }

            if (count($tables) > 1) {
                $plan['blockers'][] = 'Migration '.basename($migration).' also changes '.implode(', ', array_diff($tables, [$table])).' — edit it manually first.';

                continue;
            }

            $plan['delete'][] = $migration;
            $plan['migrations'][] = pathinfo($migration, PATHINFO_FILENAME);
        }

        if ($plan['rows'] !== null && $plan['migrations'] === []) {
            $plan['warnings'][] = "No migration creates table {$table} — a restore from the trash can bring back the files but not the table or its rows.";
        }

        // ── Relationship methods in other models ──
        foreach ($this->files->glob(app_path('Models/*.php')) as $file) {
            if ($this->normalise($file) === $this->normalise($modelFile)) {
                continue;
            }

            $this->planRelationEdits($file, $model, $plan);
        }

        // ── DatabaseSeeder line ──
        $databaseSeeder = database_path('seeders/DatabaseSeeder.php');

        if ($this->files->exists($databaseSeeder)) {
            $source = $this->files->get($databaseSeeder);
            $updated = preg_replace([
                '/^[ \t]*'.$name.'Seeder::class,[ \t]*\R/m',
                '/^[ \t]*\$this->call\(\s*'.$name.'Seeder::class\s*\);[ \t]*\R/m',
            ], '', $source);

            if ($updated !== $source) {
                $plan['edits'][$databaseSeeder] = ['description' => "Remove {$name}Seeder from DatabaseSeeder", 'content' => $updated];
            }
        }

        // ── Anything else still referencing the model? Then deleting would break the app. ──
        foreach ($this->leftoverReferences($model, $plan) as $reference) {
            $plan['blockers'][] = "{$reference} still uses {$name} — remove that code first.";
        }

        // ── Permissions ──
        $plan['permissions'] = rescue(
            fn () => Permission::query()->where('name', 'like', PermissionSync::prefixFor($model).'.%')->pluck('name')->all(),
            [],
            report: false,
        );

        $plan['delete'] = array_values(array_unique($plan['delete']));
        $plan['blockers'] = array_values(array_unique($plan['blockers']));

        return $plan;
    }

    /**
     * Human-readable preview lines.
     *
     * @return list<string>
     */
    public function describe(array $plan): array
    {
        $lines = array_map(fn (string $path): string => 'Delete '.$this->relative($path).($this->files->isDirectory($path) ? '/' : ''), $plan['delete']);

        foreach ($plan['edits'] as $path => $edit) {
            $lines[] = $edit['description'].' ('.$this->relative($path).')';
        }

        if ($plan['rows'] !== null) {
            $lines[] = "Drop table {$plan['table']} ({$plan['rows']} ".Str::plural('row', $plan['rows']).')';
        }

        if ($plan['permissions'] !== []) {
            $lines[] = 'Delete '.count($plan['permissions']).' permissions ('.PermissionSync::prefixFor($plan['model']).'.*) and their assignments';
        }

        return $lines;
    }

    /**
     * Delete one module. Backs it up to the trash first (unless trash_days = 0).
     *
     * @return array{deleted: list<string>, edited: list<string>, warnings: list<string>, trash: ?string}
     *
     * @throws ModuleBuilderException
     */
    public function delete(string $model): array
    {
        if (! ModuleBuilder::enabled()) {
            throw ModuleBuilderException::invalid(['Module Builder is disabled. Set FILALAUNCH_BUILDER_ENABLED=true in .env to enable it.']);
        }

        $plan = $this->inspect($model);

        if ($plan['blockers'] !== []) {
            throw ModuleBuilderException::invalid($plan['blockers']);
        }

        // In-memory backup for rollback (independent of the trash setting).
        $backup = [];

        foreach ($plan['delete'] as $path) {
            foreach ($this->filesIn($path) as $file) {
                $backup[$file] = $this->files->get($file);
            }
        }

        foreach (array_keys($plan['edits']) as $path) {
            $backup[$path] = $this->files->get($path);
        }

        $trashId = $this->trash->store($plan, $backup);

        try {
            foreach ($plan['edits'] as $path => $edit) {
                $this->files->put($path, $edit['content']);
                $this->invalidateOpcache($path);
            }

            foreach ($plan['delete'] as $path) {
                $this->invalidateOpcache($path);
                $this->files->isDirectory($path) ? $this->files->deleteDirectory($path) : $this->files->delete($path);
            }

            DB::transaction(function () use ($plan): void {
                if ($plan['permissions'] !== []) {
                    Permission::query()->whereIn('name', $plan['permissions'])->get()->each->delete();
                }

                if ($plan['migrations'] !== []) {
                    DB::table(config('database.migrations.table', 'migrations'))->whereIn('migration', $plan['migrations'])->delete();
                }
            });

            if ($plan['table'] !== null) {
                Schema::disableForeignKeyConstraints();
                Schema::dropIfExists($plan['table']);
                Schema::enableForeignKeyConstraints();
            }
        } catch (Throwable $e) {
            foreach ($backup as $path => $contents) {
                $this->files->ensureDirectoryExists(dirname($path));
                $this->files->put($path, $contents);
                $this->invalidateOpcache($path);
            }

            if ($trashId !== null) {
                $this->trash->purge($trashId);
            }

            throw ModuleBuilderException::failed($e);
        }

        rescue(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions(), report: false);

        $warnings = [...$plan['warnings'], ...$this->refreshAppCaches(array_keys($plan['edits']))];

        $this->trash->prune();

        return [
            'deleted' => array_map(fn (string $path): string => $this->relative($path), $plan['delete']),
            'edited' => array_map(fn (string $path): string => $this->relative($path), array_keys($plan['edits'])),
            'warnings' => $warnings,
            'trash' => $trashId,
        ];
    }

    /**
     * Delete every deletable module, dependents first.
     *
     * @return array{deleted: list<string>, blocked: array<string, list<string>>}
     */
    public function deleteAll(): array
    {
        $deleted = [];

        // Each pass deletes modules nothing depends on; dependencies clear as their dependents go.
        for ($pass = 0; $pass < 50; $pass++) {
            $ready = array_filter($this->modules(), fn (array $module): bool => $module['blockers'] === []);

            if ($ready === []) {
                break;
            }

            foreach ($ready as $module) {
                $this->delete($module['model']);
                $deleted[] = $module['name'];
            }
        }

        return [
            'deleted' => $deleted,
            'blocked' => array_map(fn (array $module): array => $module['blockers'], $this->modules()),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * @return array<class-string<Model>, list<class-string>>
     */
    protected function resourcesByModel(): array
    {
        $map = [];

        foreach (rescue(fn () => Filament::getPanel('admin')->getResources(), [], report: false) as $resource) {
            $model = rescue(fn () => $resource::getModel(), null, report: false);

            // Skip resources/models already deleted in this request (classes stay loaded in memory).
            if (is_string($model) && class_exists($resource) && is_file((new ReflectionClass($resource))->getFileName())
                && class_exists($model) && is_file((string) (new ReflectionClass($model))->getFileName())) {
                $map[ltrim($model, '\\')][] = $resource;
            }
        }

        return $map;
    }

    /**
     * Folder (v4 layout) or class file + sibling folder (v3 layout) of a resource.
     *
     * @return list<string>
     */
    protected function resourcePaths(string $resource): array
    {
        $file = (new ReflectionClass($resource))->getFileName();
        $resourcesDir = $this->normalise(app_path('Filament/Resources'));
        $dir = $this->normalise(dirname($file));

        if ($dir !== $resourcesDir && str_starts_with($dir, $resourcesDir.'/') && substr_count(Str::after($dir, $resourcesDir.'/'), '/') === 0) {
            return [dirname($file)];
        }

        $paths = [$file];
        $sibling = dirname($file).'/'.pathinfo($file, PATHINFO_FILENAME);

        if ($this->files->isDirectory($sibling)) {
            $paths[] = $sibling;
        }

        return $paths;
    }

    /**
     * Find relationship methods in $file that point to $model.
     */
    protected function planRelationEdits(string $file, string $model, array &$plan): void
    {
        $source = $plan['edits'][$file]['content'] ?? $this->files->get($file);
        $name = class_basename($model);
        $classPattern = '(?<![\w\\\\])(?:\\\\?App\\\\Models\\\\)?'.preg_quote($name, '/').'::class';

        if (! preg_match('/'.$classPattern.'/', $source)) {
            return;
        }

        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

        if (! class_exists($class)) {
            return;
        }

        $lines = explode("\n", $source);
        $remove = [];
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($this->normalise((string) $method->getFileName()) !== $this->normalise($file)) {
                continue;
            }

            $start = $method->getStartLine() - 1;
            $end = $method->getEndLine() - 1;
            $body = implode("\n", array_slice($lines, $start, $end - $start + 1));

            if (! preg_match('/'.$classPattern.'/', $body)) {
                continue;
            }

            $short = class_basename($class);

            if (preg_match('/\$this->('.implode('|', self::BLOCKING_RELATIONS).')\(\s*'.$classPattern.'/', $body, $m)) {
                $plan['blockers'][] = "{$short}::{$method->getName()}() is a {$m[1]} relation to {$name} — delete {$short} first or remove that method.";

                continue;
            }

            if (! preg_match('/^\s*(?:public\s+)?function\s+\w+\s*\(\s*\)[^{]*\{\s*return\s+\$this->('.implode('|', self::REMOVABLE_RELATIONS).')\(\s*'.$classPattern.'\s*(?:,[^;]*)?\)\s*;\s*\}\s*$/s', $body)) {
                $plan['blockers'][] = "{$short}::{$method->getName()}() uses {$name} — remove it manually.";

                continue;
            }

            // Include the docblock and one blank line above.
            $from = $start;

            if ($method->getDocComment() !== false) {
                while ($from > 0 && ! str_contains($lines[$from], '/**')) {
                    $from--;
                }
            }

            if ($from > 0 && trim($lines[$from - 1]) === '') {
                $from--;
            }

            $remove = [...$remove, ...range($from, $end)];
            $methods[] = $method->getName().'()';
        }

        if ($remove === []) {
            return;
        }

        $kept = array_values(array_diff_key($lines, array_flip($remove)));
        $updated = implode("\n", $kept);

        // Drop relation imports (and the model import) nothing uses anymore.
        foreach (['HasMany', 'HasOne'] as $relation) {
            $updated = $this->removeUnusedImport($updated, "Illuminate\\Database\\Eloquent\\Relations\\{$relation}", $relation);
        }

        $updated = $this->removeUnusedImport($updated, $model, $name);

        try {
            token_get_all($updated, TOKEN_PARSE);
        } catch (ParseError $e) {
            $plan['blockers'][] = 'Removing '.implode(', ', $methods).' from '.$this->relative($file)." would break it ({$e->getMessage()}) — edit it manually.";

            return;
        }

        $plan['edits'][$file] = [
            'description' => 'Remove '.implode(', ', $methods).' from '.class_basename($class),
            'content' => $updated,
        ];
    }

    protected function removeUnusedImport(string $source, string $class, string $short): string
    {
        $import = '/^use '.preg_quote($class, '/').';\R/m';

        if (! preg_match($import, $source)) {
            return $source;
        }

        $withoutImport = preg_replace($import, '', $source);

        return preg_match('/\b'.preg_quote($short, '/').'\b/', $withoutImport) ? $source : $withoutImport;
    }

    /**
     * Files (outside the ones being deleted) that would still reference the model after the edits.
     *
     * @return list<string>
     */
    protected function leftoverReferences(string $model, array $plan): array
    {
        $name = class_basename($model);
        $patterns = [
            '/'.preg_quote($model, '/').'\b/',
            '/(?<![\w\\\\$>])'.preg_quote($name, '/').'::/',
        ];

        $deleted = array_map(fn (string $path): string => $this->normalise($path), $plan['delete']);
        $found = [];

        foreach (['app', 'database', 'routes', 'config'] as $dir) {
            foreach ($this->files->allFiles(base_path($dir)) as $file) {
                $path = $this->normalise($file->getPathname());

                if ($file->getExtension() !== 'php' || collect($deleted)->contains(fn (string $d): bool => $path === $d || str_starts_with($path, $d.'/'))) {
                    continue;
                }

                $source = $this->withoutComments($plan['edits'][$file->getPathname()]['content'] ?? $file->getContents());

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $source)) {
                        $found[] = $this->relative($file->getPathname());

                        break;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * PHP source with comments and docblocks removed (so mentions in comments don't count as usage).
     */
    protected function withoutComments(string $source): string
    {
        try {
            $tokens = token_get_all($source);
        } catch (Throwable) {
            return $source;
        }

        return collect($tokens)
            ->reject(fn ($token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token): string => is_array($token) ? $token[1] : $token)
            ->implode('');
    }

    /**
     * @return list<string>
     */
    protected function filesIn(string $path): array
    {
        if ($this->files->isDirectory($path)) {
            return array_map(fn ($file): string => $file->getPathname(), $this->files->allFiles($path));
        }

        return $this->files->exists($path) ? [$path] : [];
    }

    protected function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    public function relative(string $path): string
    {
        return Str::after($this->normalise($path), $this->normalise(base_path()).'/');
    }
}
