<?php

namespace App\Support\ModuleBuilder;

use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Normalised, validated description of a module to generate.
 *
 * fields:    [['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null], ...]
 * relations: [['related' => 'App\Models\Category', 'inverse' => 'has_many', 'nullable' => false, 'on_delete' => 'cascade'], ...]
 *            Every relation is a belongs-to on the new model; "inverse" adds has_many / has_one to the related model.
 */
final class ModuleDefinition
{
    public const FIELD_TYPES = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'json'];

    public const DEFAULTABLE_TYPES = ['string', 'integer', 'decimal', 'boolean'];

    public const INVERSE_TYPES = ['has_many' => 'Has many', 'has_one' => 'Has one', 'none' => 'None'];

    public const ON_DELETE = ['cascade' => 'Cascade (delete children)', 'null' => 'Set null', 'restrict' => 'Restrict'];

    protected const RESERVED_MODELS = ['Model', 'User', 'Role', 'Permission', 'Pivot', 'Builder', 'Factory', 'Seeder', 'Resource', 'Policy'];

    protected const RESERVED_COLUMNS = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'attributes', 'original', 'changes', 'casts', 'table', 'connection', 'exists',
        'incrementing', 'timestamps', 'relations', 'fillable', 'guarded', 'hidden', 'visible', 'appends', 'key', 'query',
    ];

    protected const PHP_RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue',
        'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final', 'finally', 'float', 'fn', 'for',
        'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof', 'insteadof', 'int',
        'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or',
        'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'return', 'self', 'static', 'string',
        'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    /**
     * @param  list<array{name: string, type: string, nullable: bool, unique: bool, default: ?string}>  $fields
     * @param  list<array{related: string, inverse: string, nullable: bool, on_delete: string}>  $relations
     */
    public function __construct(
        public readonly string $model,
        public readonly array $fields,
        public readonly array $relations = [],
        public readonly bool $softDeletes = false,
        public readonly bool $factory = false,
        public readonly bool $seeder = false,
        public readonly int $seedCount = 10,
        public readonly bool $runSeeder = true,
        public readonly bool $policy = false,
        public readonly ?string $navigationIcon = null,
        public readonly ?string $navigationGroup = null,
        public readonly ?int $navigationSort = null,
    ) {}

    /**
     * Build from loose input (Filament form state or console options).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fields = collect($data['fields'] ?? [])
            ->filter(fn ($field): bool => filled($field['name'] ?? null))
            ->map(fn (array $field): array => [
                'name' => self::columnName((string) $field['name']),
                'type' => (string) ($field['type'] ?? 'string'),
                'nullable' => (bool) ($field['nullable'] ?? false),
                'unique' => (bool) ($field['unique'] ?? false),
                'default' => filled($field['default'] ?? null) ? trim((string) $field['default']) : null,
            ])
            ->values()
            ->all();

        $relations = collect($data['relations'] ?? [])
            ->filter(fn ($relation): bool => filled($relation['related'] ?? null))
            ->map(function (array $relation): array {
                $onDelete = (string) ($relation['on_delete'] ?? 'cascade');

                return [
                    'related' => static::modelClass((string) $relation['related']),
                    'inverse' => (string) ($relation['inverse'] ?? 'none'),
                    // "Set null" only works on a nullable column.
                    'nullable' => (bool) ($relation['nullable'] ?? false) || $onDelete === 'null',
                    'on_delete' => $onDelete,
                ];
            })
            ->values()
            ->all();

        $seeder = (bool) ($data['seeder'] ?? false);

        return new self(
            model: Str::of((string) ($data['model'] ?? ''))->trim()->singular()->studly()->toString(),
            fields: $fields,
            relations: $relations,
            softDeletes: (bool) ($data['soft_deletes'] ?? false),
            factory: $seeder || (bool) ($data['factory'] ?? false), // a seeder needs a factory
            seeder: $seeder,
            seedCount: max(1, min(10000, (int) ($data['seed_count'] ?? 10))),
            runSeeder: (bool) ($data['run_seeder'] ?? true),
            policy: (bool) ($data['policy'] ?? false),
            navigationIcon: filled($data['navigation_icon'] ?? null) ? (self::iconCase((string) $data['navigation_icon']) ?? (string) $data['navigation_icon']) : null,
            navigationGroup: filled($data['navigation_group'] ?? null) ? trim((string) $data['navigation_group']) : null,
            navigationSort: filled($data['navigation_sort'] ?? null) && is_numeric($data['navigation_sort']) ? (int) $data['navigation_sort'] : null,
        );
    }

    /**
     * Existing App\Models classes that a new module can belong to.
     *
     * @return array<class-string<Model>, string> class => short name
     */
    public static function availableModels(): array
    {
        return collect(File::exists(app_path('Models')) ? File::files(app_path('Models')) : [])
            ->map(fn ($file): string => 'App\\Models\\'.$file->getFilenameWithoutExtension())
            ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Model::class))
            ->mapWithKeys(fn (string $class): array => [$class => class_basename($class)])
            ->sort()
            ->all();
    }

    /**
     * Forgiving column name: "Title" -> title, "Published At" -> published_at,
     * "publishedAt" -> published_at, "TITLE" -> title.
     */
    public static function columnName(string $name): string
    {
        $name = trim(preg_replace('/[\s\-]+/', ' ', $name));

        if ($name === mb_strtoupper($name)) {
            $name = mb_strtolower($name);
        }

        return Str::of($name)->snake()->lower()->replaceMatches('/_+/', '_')->toString();
    }

    public static function modelClass(string $name): string
    {
        return str_contains($name, '\\') ? ltrim($name, '\\') : 'App\\Models\\'.Str::studly($name);
    }

    // ── Navigation ───────────────────────────────────────────────────

    /**
     * Resolve "newspaper", "o-newspaper" or "OutlinedNewspaper" to a Heroicon case name.
     */
    public static function iconCase(string $icon): ?string
    {
        $icon = trim($icon);
        $cases = collect(Heroicon::cases());

        // Prefer the outlined style used across the sidebar ("tag" -> OutlinedTag, not solid Tag).
        return ($cases->first(fn (Heroicon $case): bool => $case->name === 'Outlined'.Str::studly($icon))
            ?? $cases->first(fn (Heroicon $case): bool => $case->name === $icon)
            ?? $cases->first(fn (Heroicon $case): bool => $case->value === $icon))?->name;
    }

    /**
     * Outlined Heroicons for the icon picker: case name => label.
     *
     * @return array<string, string>
     */
    public static function iconOptions(?string $search = null, int $limit = 50): array
    {
        return collect(Heroicon::cases())
            ->filter(fn (Heroicon $case): bool => str_starts_with($case->name, 'Outlined'))
            ->mapWithKeys(fn (Heroicon $case): array => [$case->name => Str::headline(Str::after($case->name, 'Outlined'))])
            ->filter(fn (string $label): bool => blank($search) || Str::contains(strtolower($label), strtolower(trim($search))))
            ->take($limit)
            ->all();
    }

    /**
     * Navigation groups already used in the admin panel (for suggestions).
     *
     * @return list<string>
     */
    public static function existingNavigationGroups(): array
    {
        try {
            $panel = Filament::getPanel('admin');
        } catch (Throwable) {
            return [];
        }

        return collect([...$panel->getResources(), ...$panel->getPages()])
            ->map(fn (string $class): mixed => rescue(fn () => $class::getNavigationGroup(), report: false))
            ->filter(fn (mixed $group): bool => is_string($group) && $group !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    // ── Naming ────────────────────────────────────────────────────────

    public function modelFqcn(): string
    {
        return 'App\\Models\\'.$this->model;
    }

    public function table(): string
    {
        return Str::snake(Str::pluralStudly($this->model));
    }

    public function pluralStudly(): string
    {
        return Str::pluralStudly($this->model);
    }

    public static function foreignKey(string $relatedClass): string
    {
        return Str::snake(class_basename($relatedClass)).'_id';
    }

    public static function belongsToMethod(string $relatedClass): string
    {
        return Str::camel(class_basename($relatedClass));
    }

    public function inverseMethod(string $inverse): string
    {
        return $inverse === 'has_one'
            ? Str::camel($this->model)
            : Str::camel($this->pluralStudly());
    }

    /**
     * Column used to label records in the UI: "name" / "title", else the first string field.
     */
    public function titleAttribute(): ?string
    {
        $strings = collect($this->fields)->where('type', 'string')->pluck('name');

        return $strings->first(fn (string $name): bool => in_array($name, ['name', 'title'], true)) ?? $strings->first();
    }

    // ── Validation ───────────────────────────────────────────────────

    /**
     * @return list<string> human-readable errors (empty = valid)
     */
    public function errors(): array
    {
        $errors = [];

        // Model
        if ($this->model === '') {
            return ['Model name is required.'];
        }

        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $this->model)) {
            $errors[] = "Model name [{$this->model}] must start with a letter and contain only letters and numbers.";
        }

        if (in_array($this->model, self::RESERVED_MODELS, true) || in_array(strtolower($this->model), self::PHP_RESERVED, true)) {
            $errors[] = "Model name [{$this->model}] is reserved.";
        }

        $paths = [
            app_path("Models/{$this->model}.php") => "Model [{$this->modelFqcn()}] already exists.",
            app_path("Filament/Resources/{$this->pluralStudly()}") => "Filament resource folder [app/Filament/Resources/{$this->pluralStudly()}] already exists.",
        ];

        if ($this->factory) {
            $paths[database_path("factories/{$this->model}Factory.php")] = "Factory [{$this->model}Factory] already exists.";
        }

        if ($this->seeder) {
            $paths[database_path("seeders/{$this->model}Seeder.php")] = "Seeder [{$this->model}Seeder] already exists.";
        }

        if ($this->policy) {
            $paths[app_path("Policies/{$this->model}Policy.php")] = "Policy [{$this->model}Policy] already exists.";
        }

        foreach ($paths as $path => $message) {
            if (File::exists($path)) {
                $errors[] = $message;
            }
        }

        try {
            if (Schema::hasTable($this->table())) {
                $errors[] = "Table [{$this->table()}] already exists.";
            }
        } catch (Throwable) {
            $errors[] = 'Database is not reachable — run migrations first.';
        }

        if (File::glob(database_path("migrations/*_create_{$this->table()}_table.php")) !== []) {
            $errors[] = "A migration creating [{$this->table()}] already exists.";
        }

        // Fields
        if ($this->fields === []) {
            $errors[] = 'Add at least one field.';
        }

        $columns = [];

        foreach ($this->fields as $field) {
            $name = $field['name'];

            if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                $errors[] = "Field [{$name}] must be snake_case (letters, numbers, underscores) and start with a letter.";
            } elseif (in_array($name, self::RESERVED_COLUMNS, true) || in_array($name, self::PHP_RESERVED, true)) {
                $errors[] = "Field name [{$name}] is reserved.";
            }

            if (in_array($name, $columns, true)) {
                $errors[] = "Field [{$name}] is defined twice.";
            }

            $columns[] = $name;

            if (! in_array($field['type'], self::FIELD_TYPES, true)) {
                $errors[] = "Field [{$name}] has an unknown type [{$field['type']}].";

                continue;
            }

            if ($field['unique'] && in_array($field['type'], ['text', 'json', 'boolean'], true)) {
                $errors[] = "Field [{$name}]: {$field['type']} columns can't be unique.";
            }

            if ($field['default'] !== null) {
                if (! in_array($field['type'], self::DEFAULTABLE_TYPES, true)) {
                    $errors[] = "Field [{$name}]: {$field['type']} columns can't have a default value.";
                } elseif ($field['type'] === 'integer' && filter_var($field['default'], FILTER_VALIDATE_INT) === false) {
                    $errors[] = "Field [{$name}]: default must be a whole number.";
                } elseif ($field['type'] === 'decimal' && ! is_numeric($field['default'])) {
                    $errors[] = "Field [{$name}]: default must be a number.";
                } elseif ($field['type'] === 'boolean' && ! in_array(strtolower($field['default']), ['true', 'false', '1', '0'], true)) {
                    $errors[] = "Field [{$name}]: default must be true or false.";
                }
            }
        }

        // Relations (belongs-to + optional inverse)
        $related = [];

        foreach ($this->relations as $relation) {
            $class = $relation['related'];
            $short = class_basename($class);

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                $errors[] = "Related model [{$class}] doesn't exist.";

                continue;
            }

            if (in_array($class, $related, true)) {
                $errors[] = "Belongs to [{$short}] is defined twice.";
            }

            $related[] = $class;

            $foreignKey = self::foreignKey($class);
            $method = self::belongsToMethod($class);

            if (in_array($foreignKey, $columns, true) || in_array($method, $columns, true)) {
                $errors[] = "Field names [{$foreignKey}] / [{$method}] are used by the belongs-to {$short} relationship.";
            }

            if (! array_key_exists($relation['inverse'], self::INVERSE_TYPES)) {
                $errors[] = "Unknown inverse relationship [{$relation['inverse']}] for {$short}.";
            } elseif ($relation['inverse'] !== 'none') {
                if (! str_starts_with($class, 'App\\Models\\')) {
                    $errors[] = "Can only add {$relation['inverse']} to models in App\\Models ({$short}).";
                } elseif (method_exists($class, $inverse = $this->inverseMethod($relation['inverse']))) {
                    $errors[] = "{$short} already has a {$inverse}() method — choose \"None\" as the inverse.";
                }
            }

            if (! array_key_exists($relation['on_delete'], self::ON_DELETE)) {
                $errors[] = "Unknown on-delete action [{$relation['on_delete']}] for {$short}.";
            }
        }

        // Menu
        if ($this->navigationIcon !== null && ! defined(Heroicon::class.'::'.$this->navigationIcon)) {
            $errors[] = "Unknown menu icon [{$this->navigationIcon}].";
        }

        if ($this->navigationGroup !== null && (mb_strlen($this->navigationGroup) > 64 || preg_match('/[\r\n]/', $this->navigationGroup))) {
            $errors[] = 'Navigation group must be a single line of up to 64 characters.';
        }

        return $errors;
    }
}
