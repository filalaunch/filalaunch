<?php

namespace App\Support\ModuleBuilder;

use App\Support\Permissions\PermissionSync;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Renders the PHP source for each generated file from stubs/module-builder/*.stub,
 * plus safe text edits for existing files (inverse relationship, DatabaseSeeder, resource tweaks).
 */
class ModuleFiles
{
    public function __construct(protected ModuleDefinition $definition) {}

    // ── New files ────────────────────────────────────────────────────

    public function migration(): string
    {
        $d = $this->definition;
        $lines = [];

        if ($d->belongsToTeam) {
            $lines[] = "            \$table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();";
        }

        foreach ($d->fields as $field) {
            $column = match ($field['type']) {
                'decimal' => "\$table->decimal('{$field['name']}', 10, 2)",
                'datetime' => "\$table->dateTime('{$field['name']}')",
                default => "\$table->{$field['type']}('{$field['name']}')",
            };

            if ($field['nullable']) {
                $column .= '->nullable()';
            }

            if ($field['default'] !== null) {
                $column .= '->default('.$this->defaultLiteral($field['type'], $field['default']).')';
            }

            if ($field['unique']) {
                $column .= '->unique()';
            }

            $lines[] = "            {$column};";
        }

        foreach ($d->relations as $relation) {
            $column = "\$table->foreignId('".ModuleDefinition::foreignKey($relation['related'])."')";

            if ($relation['nullable']) {
                $column .= '->nullable()';
            }

            $column .= "->constrained('".(new $relation['related'])->getTable()."')";
            $column .= match ($relation['on_delete']) {
                'null' => '->nullOnDelete()',
                'restrict' => '->restrictOnDelete()',
                default => '->cascadeOnDelete()',
            };

            $lines[] = "            {$column};";
        }

        return $this->stub('migration', [
            'table' => $d->table(),
            'columns' => implode("\n", $lines),
            'softDeletes' => $d->softDeletes ? "\n            \$table->softDeletes();" : '',
        ]);
    }

    public function model(): string
    {
        $d = $this->definition;

        $imports = ['Illuminate\\Database\\Eloquent\\Model'];
        $traits = [];
        $traitDoc = '';

        if ($d->factory) {
            $imports[] = "Database\\Factories\\{$d->model}Factory";
            $imports[] = 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory';
            $traits[] = 'HasFactory';
            $traitDoc = "    /** @use HasFactory<{$d->model}Factory> */\n";
        }

        if ($d->softDeletes) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\SoftDeletes';
            $traits[] = 'SoftDeletes';
        }

        if ($d->belongsToTeam) {
            $imports[] = 'App\\Models\\Concerns\\BelongsToTeam';
            $traits[] = 'BelongsToTeam';
        }

        $fillable = collect($d->belongsToTeam ? ['team_id'] : [])
            ->merge(collect($d->fields)->pluck('name'))
            ->merge(collect($d->relations)->map(fn (array $r): string => ModuleDefinition::foreignKey($r['related'])))
            ->map(fn (string $column): string => "        '{$column}',")
            ->implode("\n");

        $casts = collect($d->fields)
            ->mapWithKeys(fn (array $field): array => [$field['name'] => match ($field['type']) {
                'integer' => 'integer',
                'decimal' => 'decimal:2',
                'boolean' => 'boolean',
                'date' => 'date',
                'datetime' => 'datetime',
                'json' => 'array',
                default => null,
            }])
            ->filter();

        $castsBlock = $casts->isEmpty() ? '' : "\n\n    /**\n     * Get the attributes that should be cast.\n     *\n     * @return array<string, string>\n     */\n"
            ."    protected function casts(): array\n    {\n        return [\n"
            .$casts->map(fn (string $cast, string $column): string => "            '{$column}' => '{$cast}',")->implode("\n")
            ."\n        ];\n    }";

        $relations = '';

        if ($d->relations !== []) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo';
        }

        foreach ($d->relations as $relation) {
            $related = $relation['related'];
            $short = class_basename($related);

            if (! str_starts_with($related, 'App\\Models\\')) {
                $imports[] = $related;
            }

            $relations .= "\n\n    /**\n     * @return BelongsTo<{$short}, \$this>\n     */\n"
                .'    public function '.ModuleDefinition::belongsToMethod($related)."(): BelongsTo\n    {\n"
                ."        return \$this->belongsTo({$short}::class);\n    }";
        }

        $source = $this->stub('model', [
            'imports' => $this->useLines($imports),
            'class' => $d->model,
            'fillable' => $fillable,
            'casts' => $castsBlock,
            'relations' => $relations,
        ]);

        return str_replace(
            "    {{ traits }}\n\n",
            $traits === [] ? '' : $traitDoc.'    use '.implode(', ', $traits).";\n\n",
            $source,
        );
    }

    public function factory(): string
    {
        $d = $this->definition;
        $imports = ["App\\Models\\{$d->model}", 'Illuminate\\Database\\Eloquent\\Factories\\Factory'];
        $lines = [];

        if ($d->belongsToTeam) {
            $imports[] = 'App\\Models\\Team';
            $lines[] = "            'team_id' => fn () => Team::query()->inRandomOrder()->value('id'),";
        }

        foreach ($d->fields as $field) {
            $lines[] = "            '{$field['name']}' => ".$this->fakerFor($field).',';
        }

        foreach ($d->relations as $relation) {
            $related = $relation['related'];
            $short = class_basename($related);
            $imports[] = $related;

            // Users are real, loginable accounts — never fake them; pick an existing one.
            $value = $related !== config('auth.providers.users.model') && class_exists(Factory::resolveFactoryName($related))
                ? "{$short}::factory()"
                : "fn () => {$short}::query()->inRandomOrder()->value('id')";

            $lines[] = "            '".ModuleDefinition::foreignKey($related)."' => {$value},";
        }

        return $this->stub('factory', [
            'imports' => $this->useLines($imports),
            'class' => $d->model,
            'definition' => implode("\n", $lines),
        ]);
    }

    public function seeder(): string
    {
        return $this->stub('seeder', [
            'class' => $this->definition->model,
            'count' => (string) $this->definition->seedCount,
            'table' => $this->definition->table(),
        ]);
    }

    public function policy(): string
    {
        return str_replace(
            ['{{ model }}', '{{ prefix }}'],
            [$this->definition->model, PermissionSync::prefixFor($this->definition->modelFqcn())],
            file_get_contents(base_path('stubs/filalaunch-policy.stub')),
        );
    }

    // ── Edits to existing files ──────────────────────────────────────

    /**
     * Append a hasMany / hasOne method to the related model (before its closing brace).
     */
    public function addInverseRelation(string $source, string $inverse): string
    {
        $d = $this->definition;
        $type = $inverse === 'has_one' ? 'HasOne' : 'HasMany';
        $call = $inverse === 'has_one' ? 'hasOne' : 'hasMany';
        $method = $d->inverseMethod($inverse);

        $block = "    /**\n     * @return {$type}<{$d->model}, \$this>\n     */\n"
            ."    public function {$method}(): {$type}\n    {\n"
            ."        return \$this->{$call}({$d->model}::class);\n    }";

        $source = $this->addImport($source, "Illuminate\\Database\\Eloquent\\Relations\\{$type}");

        $closing = strrpos($source, '}');
        $before = rtrim(substr($source, 0, $closing));
        $separator = str_ends_with($before, '{') ? "\n" : "\n\n";

        return $before.$separator.$block."\n".substr($source, $closing);
    }

    /**
     * Add "{Model}Seeder::class," to the $this->call([...]) list. Returns null when the list isn't found.
     */
    public function registerSeeder(string $source): ?string
    {
        $seeder = "{$this->definition->model}Seeder::class";

        if (str_contains($source, $seeder)) {
            return $source;
        }

        $updated = preg_replace_callback(
            '/(\$this->call\(\[)(.*?)(\n([ \t]*)\]\);)/s',
            fn (array $m): string => $m[1].rtrim($m[2])."\n{$m[4]}    {$seeder},".$m[3],
            $source,
            1,
            $count,
        );

        return $count === 1 ? $updated : null;
    }

    /**
     * Bulk actions must authorize each record (BUILD-SPEC invariant I10).
     */
    public function hardenTable(string $source): string
    {
        return preg_replace_callback(
            '/^([ \t]*)(DeleteBulkAction|ForceDeleteBulkAction|RestoreBulkAction)::make\(\),$/m',
            fn (array $m): string => "{$m[1]}{$m[2]}::make()\n{$m[1]}    ->authorizeIndividualRecords('".match ($m[2]) {
                'ForceDeleteBulkAction' => 'forceDelete',
                'RestoreBulkAction' => 'restore',
                default => 'delete',
            }."'),",
            $source,
        );
    }

    /**
     * Set the menu icon, navigation group and sort order on the generated resource class.
     */
    public function applyNavigation(string $source): string
    {
        $d = $this->definition;
        $iconLine = '/^([ \t]*)protected static string\|BackedEnum\|null \$navigationIcon = [^;]+;$/m';

        if ($d->navigationIcon !== null) {
            $source = preg_replace($iconLine, '$1protected static string|BackedEnum|null $navigationIcon = Heroicon::'.$d->navigationIcon.';', $source, 1);
        }

        $extra = '';

        if ($d->navigationGroup !== null) {
            $extra .= "\n\n    protected static string|UnitEnum|null \$navigationGroup = ".var_export($d->navigationGroup, true).';';
            $source = $this->addImport($source, 'UnitEnum');
        }

        if ($d->navigationSort !== null) {
            $extra .= "\n\n    protected static ?int \$navigationSort = {$d->navigationSort};";
        }

        if ($extra === '') {
            return $source;
        }

        return preg_replace_callback($iconLine, fn (array $m): string => $m[0].$extra, $source, 1);
    }

    /**
     * Team-owned resources: the team is set by the panel, so drop the generated team select/column.
     */
    public function removeTeamFields(string $source): string
    {
        $source = preg_replace([
            "/^[ \t]*Select::make\('team_id'\)(?:\R[ \t]*->[^\r\n]*)*,\R/m",
            "/^[ \t]*TextColumn::make\('team\.name'\)(?:\R[ \t]*->[^\r\n]*)*,\R/m",
            "/^[ \t]*TextColumn::make\('team_id'\)(?:\R[ \t]*->[^\r\n]*)*,\R/m",
        ], '', $source);

        foreach (['Filament\\Forms\\Components\\Select' => 'Select::', 'Filament\\Tables\\Columns\\TextColumn' => 'TextColumn::'] as $class => $usage) {
            if (! str_contains($source, $usage)) {
                $source = preg_replace('/^use '.preg_quote($class, '/').';\R/m', '', $source);
            }
        }

        return $source;
    }

    /**
     * json columns are cast to arrays — edit them with KeyValue instead of Textarea.
     */
    public function adjustForm(string $source): string
    {
        $changed = false;

        foreach (collect($this->definition->fields)->where('type', 'json') as $field) {
            $search = "Textarea::make('{$field['name']}')";

            if (str_contains($source, $search)) {
                $source = str_replace($search, "KeyValue::make('{$field['name']}')", $source);
                $changed = true;
            }
        }

        if (! $changed) {
            return $source;
        }

        $source = $this->addImport($source, 'Filament\\Forms\\Components\\KeyValue');

        if (! str_contains($source, 'Textarea::')) {
            $source = preg_replace('/^use Filament\\\\Forms\\\\Components\\\\Textarea;\n/m', '', $source);
        }

        return $source;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Add a top-level "use X;" import, keeping the import block sorted.
     */
    public function addImport(string $source, string $class): string
    {
        if (preg_match('/^use '.preg_quote($class, '/').';$/m', $source)) {
            return $source;
        }

        $lines = explode("\n", $source);
        $classLine = collect($lines)->search(fn (string $line): bool => (bool) preg_match('/^(final |abstract |readonly )*class /', $line));
        $useIndexes = collect($lines)
            ->filter(fn (string $line, int $i): bool => ($classLine === false || $i < $classLine) && (bool) preg_match('/^use [^;]+;$/', $line))
            ->keys();

        if ($useIndexes->isNotEmpty()) {
            $first = $useIndexes->first();
            $last = $useIndexes->last();
            $block = array_slice($lines, $first, $last - $first + 1);
            $block[] = "use {$class};";
            $block = array_values(array_unique($block));
            usort($block, fn (string $a, string $b): int => strcasecmp($a, $b));
            array_splice($lines, $first, $last - $first + 1, $block);

            return implode("\n", $lines);
        }

        $namespaceLine = collect($lines)->search(fn (string $line): bool => str_starts_with($line, 'namespace '));
        array_splice($lines, $namespaceLine === false ? 1 : $namespaceLine + 1, 0, ['', "use {$class};"]);

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $classes
     */
    protected function useLines(array $classes): string
    {
        $classes = array_values(array_unique($classes));
        usort($classes, fn (string $a, string $b): int => strcasecmp($a, $b));

        return implode("\n", array_map(fn (string $class): string => "use {$class};", $classes));
    }

    /**
     * @param  array<string, string>  $replace
     */
    protected function stub(string $name, array $replace): string
    {
        $source = file_get_contents(base_path("stubs/module-builder/{$name}.stub"));

        foreach ($replace as $key => $value) {
            $source = str_replace('{{ '.$key.' }}', $value, $source);
        }

        return $source;
    }

    protected function defaultLiteral(string $type, string $value): string
    {
        return match ($type) {
            'boolean' => in_array(strtolower($value), ['true', '1'], true) ? 'true' : 'false',
            'integer' => (string) (int) $value,
            'decimal' => (string) (float) $value,
            default => var_export($value, true),
        };
    }

    /**
     * @param  array{name: string, type: string, unique: bool}  $field
     */
    protected function fakerFor(array $field): string
    {
        $name = $field['name'];

        $call = match ($field['type']) {
            'text' => 'paragraph()',
            'integer' => 'numberBetween(1, 1000)',
            'decimal' => 'randomFloat(2, 1, 1000)',
            'boolean' => 'boolean()',
            'date' => 'date()',
            'datetime' => 'dateTime()',
            'json' => null,
            default => match (true) {
                Str::contains($name, 'email') => 'safeEmail()',
                $name === 'name' || Str::endsWith($name, '_name') => 'name()',
                Str::contains($name, 'title') => 'sentence(4)',
                Str::contains($name, 'slug') => 'slug()',
                Str::contains($name, ['phone', 'mobile']) => 'phoneNumber()',
                Str::contains($name, ['url', 'website', 'link']) => 'url()',
                Str::contains($name, 'city') => 'city()',
                Str::contains($name, 'country') => 'country()',
                Str::contains($name, 'address') => 'address()',
                Str::contains($name, ['color', 'colour']) => 'safeColorName()',
                Str::contains($name, ['code', 'sku']) => "bothify('???-####')",
                default => 'words(3, true)',
            },
        };

        if ($call === null) {
            return "['key' => fake()->word()]";
        }

        if ($field['unique'] && $field['type'] === 'integer') {
            $call = 'numberBetween(1, 1000000)';
        }

        return 'fake()->'.($field['unique'] ? 'unique()->' : '').$call;
    }
}
