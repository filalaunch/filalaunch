<?php

namespace App\Support\ModuleBuilder;

use App\Support\ModuleBuilder\Concerns\RefreshesAppCaches;
use App\Support\Permissions\PermissionSync;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ParseError;
use RuntimeException;
use Throwable;

/**
 * Generates a complete module: migration, model, factory, seeder, policy,
 * Filament resource and permissions. Used by the Module Builder page and
 * `php artisan filalaunch:make-module`.
 *
 * Any failure before the module is complete rolls everything back:
 * created files are deleted, edited files restored, the table dropped.
 */
class ModuleBuilder
{
    use RefreshesAppCaches;

    /** @var list<string> */
    protected array $created = [];

    /** @var array<string, string> path => original contents */
    protected array $backups = [];

    protected ?string $migratedTable = null;

    protected ?string $migrationName = null;

    public function __construct(
        protected Filesystem $files,
        protected PermissionSync $permissions,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filalaunch.builder.enabled');
    }

    /**
     * Human-readable list of what build() will do.
     *
     * @return list<string>
     */
    public function plan(ModuleDefinition $d): array
    {
        $steps = [
            "Create migration database/migrations/…_create_{$d->table()}_table.php".($d->softDeletes ? ' (with soft deletes)' : ''),
            "Create model app/Models/{$d->model}.php",
        ];

        if ($d->factory) {
            $steps[] = "Create factory database/factories/{$d->model}Factory.php";
        }

        if ($d->seeder) {
            $steps[] = "Create seeder database/seeders/{$d->model}Seeder.php ({$d->seedCount} records) and register it in DatabaseSeeder";
        }

        if ($d->policy) {
            $steps[] = "Create policy app/Policies/{$d->model}Policy.php";
        }

        foreach ($d->relations as $relation) {
            if ($relation['inverse'] !== 'none') {
                $steps[] = 'Add '.$d->inverseMethod($relation['inverse']).'() to app/Models/'.class_basename($relation['related']).'.php';
            }
        }

        $steps[] = "Run the migration (create table {$d->table()})";
        $menu = array_filter([
            $d->navigationIcon ? 'icon '.Str::headline(Str::after($d->navigationIcon, 'Outlined')) : null,
            $d->navigationGroup ? "group \"{$d->navigationGroup}\"" : null,
            $d->navigationSort !== null ? "sort {$d->navigationSort}" : null,
        ]);

        $steps[] = "Create Filament resource app/Filament/Resources/{$d->pluralStudly()}".($menu ? ' (menu: '.implode(', ', $menu).')' : '');

        if ($d->seeder && $d->runSeeder) {
            $steps[] = "Seed {$d->seedCount} fake records";
        }

        $steps[] = 'Create permissions '.PermissionSync::prefixFor($d->modelFqcn()).'.* (not assigned to anyone)';

        return $steps;
    }

    /**
     * @return array{created: list<string>, modified: list<string>, warnings: list<string>, url: ?string}
     *
     * @throws ModuleBuilderException
     */
    public function build(ModuleDefinition $d): array
    {
        if (! static::enabled()) {
            throw ModuleBuilderException::invalid(['Module Builder is disabled. Set FILALAUNCH_BUILDER_ENABLED=true in .env to enable it.']);
        }

        if ($errors = $d->errors()) {
            throw ModuleBuilderException::invalid($errors);
        }

        $this->created = [];
        $this->backups = [];
        $this->migratedTable = null;
        $this->migrationName = null;

        $render = new ModuleFiles($d);
        $warnings = [];

        try {
            // 1. Source files
            $this->migrationName = date('Y_m_d_His')."_create_{$d->table()}_table";
            $migrationPath = database_path("migrations/{$this->migrationName}.php");

            $this->create($migrationPath, $render->migration());
            $this->create(app_path("Models/{$d->model}.php"), $render->model());

            if ($d->factory) {
                $this->create(database_path("factories/{$d->model}Factory.php"), $render->factory());
            }

            if ($d->seeder) {
                $this->create(database_path("seeders/{$d->model}Seeder.php"), $render->seeder());
            }

            if ($d->policy) {
                $this->create(app_path("Policies/{$d->model}Policy.php"), $render->policy());
            }

            // 2. Inverse relationships on existing models
            foreach ($d->relations as $relation) {
                if ($relation['inverse'] !== 'none') {
                    $path = app_path('Models/'.class_basename($relation['related']).'.php');
                    $this->modify($path, fn (string $source): string => $render->addInverseRelation($source, $relation['inverse']));
                }
            }

            // 3. Register seeder
            if ($d->seeder) {
                $path = database_path('seeders/DatabaseSeeder.php');
                $updated = $this->files->exists($path) ? $render->registerSeeder($this->files->get($path)) : null;

                if ($updated === null) {
                    $warnings[] = "Couldn't find \$this->call([...]) in DatabaseSeeder — add {$d->model}Seeder::class manually.";
                } else {
                    $this->modify($path, fn (): string => $updated);
                }
            }

            // 4. Migrate (only this migration)
            Artisan::call('migrate', [
                '--path' => Str::after($migrationPath, base_path().DIRECTORY_SEPARATOR),
                '--force' => true,
            ]);

            if (! Schema::hasTable($d->table())) {
                throw new RuntimeException('Migration did not create table '.$d->table().'. '.trim(Artisan::output()));
            }

            $this->migratedTable = $d->table();

            // 5. Filament resource (form + table generated from the new table)
            $resourceDir = app_path("Filament/Resources/{$d->pluralStudly()}");
            $this->created[] = $resourceDir;

            $exitCode = Artisan::call('make:filament-resource', array_filter([
                'model' => $d->model,
                '--generate' => true,
                '--soft-deletes' => $d->softDeletes,
                '--not-embedded' => true,
                '--panel' => 'admin',
                '--record-title-attribute' => $d->titleAttribute(),
                '--no-interaction' => true,
            ]));

            $resourceClass = "App\\Filament\\Resources\\{$d->pluralStudly()}\\{$d->model}Resource";
            $resourceFile = "{$resourceDir}/{$d->model}Resource.php";

            if ($exitCode !== 0 || ! $this->files->exists($resourceFile)) {
                throw new RuntimeException('Filament resource generation failed. '.trim(Artisan::output()));
            }

            $this->tweak("{$resourceDir}/Tables/{$d->pluralStudly()}Table.php", fn (string $s): string => $render->hardenTable($s));
            $this->tweak("{$resourceDir}/Schemas/{$d->model}Form.php", fn (string $s): string => $render->adjustForm($s));
            $this->tweak($resourceFile, fn (string $s): string => $render->applyNavigation($s));
        } catch (Throwable $e) {
            $this->rollback();

            throw $e instanceof ModuleBuilderException ? $e : ModuleBuilderException::failed($e);
        }

        // ── Module is complete; the remaining steps only warn on failure ──

        $seedBlocker = $d->seeder && $d->runSeeder ? $this->seedBlocker($d) : null;

        if ($seedBlocker !== null) {
            $warnings[] = $seedBlocker;
        } elseif ($d->seeder && $d->runSeeder) {
            try {
                Artisan::call('db:seed', ['--class' => "Database\\Seeders\\{$d->model}Seeder", '--force' => true]);
            } catch (Throwable $e) {
                $warnings[] = "Seeding failed: {$e->getMessage()}";
            }
        }

        try {
            $this->permissions->syncModel($d->modelFqcn());
        } catch (Throwable $e) {
            $warnings[] = "Permissions will be created on the next panel visit ({$e->getMessage()}).";
        }

        $warnings = [...$warnings, ...$this->refreshCaches()];

        return [
            'created' => array_map(fn (string $path): string => $this->relative($path), $this->created),
            'modified' => array_map(fn (string $path): string => $this->relative($path), array_keys($this->backups)),
            'warnings' => $warnings,
            'url' => $this->resourceUrl($resourceClass),
        ];
    }

    /**
     * A required belongs-to whose model has no factory needs existing records to pick from.
     */
    protected function seedBlocker(ModuleDefinition $d): ?string
    {
        foreach ($d->relations as $relation) {
            $related = $relation['related'];
            $usesFactory = $related !== config('auth.providers.users.model')
                && class_exists(Factory::resolveFactoryName($related));

            if (! $relation['nullable'] && ! $usesFactory && ! $related::query()->exists()) {
                $short = class_basename($related);

                return "Seeding skipped: {$short} has no records and no factory. Add some {$short} records, then run `php artisan db:seed --class={$d->model}Seeder`.";
            }
        }

        return null;
    }

    // ── File operations (tracked for rollback) ───────────────────────

    protected function create(string $path, string $source): void
    {
        if ($this->files->exists($path)) {
            throw new RuntimeException("{$this->relative($path)} already exists.");
        }

        $this->assertValidPhp($path, $source);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $source);
        $this->created[] = $path;
    }

    protected function modify(string $path, callable $change): void
    {
        $original = $this->files->get($path);
        $updated = $change($original);

        if ($updated === $original) {
            return;
        }

        $this->assertValidPhp($path, $updated);
        $this->backups[$path] ??= $original;
        $this->files->put($path, $updated);
        $this->invalidateOpcache($path);
    }

    /**
     * Edit a file this run created (no backup needed — it's deleted on rollback).
     */
    protected function tweak(string $path, callable $change): void
    {
        if (! $this->files->exists($path)) {
            return;
        }

        $updated = $change($this->files->get($path));
        $this->assertValidPhp($path, $updated);
        $this->files->put($path, $updated);
    }

    protected function assertValidPhp(string $path, string $source): void
    {
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException("Generated code for {$this->relative($path)} is invalid: {$e->getMessage()}");
        }
    }

    protected function rollback(): void
    {
        if ($this->migratedTable !== null) {
            rescue(fn () => Schema::dropIfExists($this->migratedTable), report: false);
        }

        if ($this->migrationName !== null) {
            rescue(fn () => DB::table(config('database.migrations.table', 'migrations'))
                ->where('migration', $this->migrationName)->delete(), report: false);
        }

        foreach (array_reverse($this->created) as $path) {
            $this->files->isDirectory($path) ? $this->files->deleteDirectory($path) : $this->files->delete($path);
        }

        foreach ($this->backups as $path => $original) {
            $this->files->put($path, $original);
            $this->invalidateOpcache($path);
        }
    }

    // ── Production-safe cache refresh ────────────────────────────────

    /**
     * New resources must be visible on the next request even when caches are on.
     *
     * @return list<string> warnings
     */
    protected function refreshCaches(): array
    {
        return $this->refreshAppCaches(array_filter($this->created, fn (string $path): bool => $this->files->isFile($path)));
    }

    protected function resourceUrl(string $resourceClass): ?string
    {
        try {
            return url(Filament::getPanel('admin')->getPath().'/'.$resourceClass::getSlug());
        } catch (Throwable) {
            return null;
        }
    }

    protected function relative(string $path): string
    {
        return str_replace('\\', '/', Str::after($path, base_path().DIRECTORY_SEPARATOR));
    }
}
