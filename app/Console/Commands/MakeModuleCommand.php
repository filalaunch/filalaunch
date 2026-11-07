<?php

namespace App\Console\Commands;

use App\Support\ModuleBuilder\ModuleBuilder;
use App\Support\ModuleBuilder\ModuleBuilderException;
use App\Support\ModuleBuilder\ModuleDefinition;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class MakeModuleCommand extends Command
{
    protected $signature = 'filalaunch:make-module
                            {name? : Model name, e.g. Post}
                            {--fields= : Comma-separated fields: name:type[:nullable][:unique][:default=value], e.g. "title:string,body:text:nullable"}
                            {--belongs-to=* : Related model[:has_many|has_one|none][:nullable], e.g. Category:has_many}
                            {--soft-deletes : Add soft deletes (trash, restore, force delete)}
                            {--factory : Create a factory}
                            {--seeder= : Create a seeder with N fake records (implies --factory)}
                            {--no-seed : Create the seeder but don\'t run it now}
                            {--policy : Scaffold a policy class for custom rules}';

    protected $description = 'Generate migration, model, factory, seeder, policy and Filament resource for a new module';

    public function handle(ModuleBuilder $builder): int
    {
        if (! ModuleBuilder::enabled()) {
            $this->components->error('Module Builder is disabled. Set FILALAUNCH_BUILDER_ENABLED=true in .env to enable it.');

            return self::FAILURE;
        }

        $interactive = $this->input->isInteractive() && (blank($this->argument('name')) || blank($this->option('fields')));

        $data = $interactive ? $this->askForDefinition() : $this->fromOptions();

        if ($data === null) {
            return self::FAILURE;
        }

        $definition = ModuleDefinition::fromArray($data);

        if ($errors = $definition->errors()) {
            $this->components->error('Please fix the following:');
            $this->components->bulletList($errors);

            return self::FAILURE;
        }

        $this->components->info("Module [{$definition->model}] plan:");
        $this->components->bulletList($builder->plan($definition));

        if ($this->input->isInteractive() && ! confirm('Generate this module?', default: true)) {
            return self::FAILURE;
        }

        try {
            $result = $builder->build($definition);
        } catch (ModuleBuilderException $e) {
            $this->components->error('Module not generated:');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        $this->components->info("Module [{$definition->model}] generated.");
        $this->components->twoColumnDetail('<fg=green>Created</>');
        $this->components->bulletList($result['created']);

        if ($result['modified'] !== []) {
            $this->components->twoColumnDetail('<fg=yellow>Updated</>');
            $this->components->bulletList($result['modified']);
        }

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        if ($result['url']) {
            $this->components->twoColumnDetail('Open', $result['url']);
        }

        $this->components->info('Grant the new permissions to a role or user under Access Control.');

        return self::SUCCESS;
    }

    /**
     * Non-interactive: build from arguments/options.
     *
     * @return array<string, mixed>|null
     */
    protected function fromOptions(): ?array
    {
        if (blank($this->argument('name')) || blank($this->option('fields'))) {
            $this->components->error('Name and --fields are required with --no-interaction.');

            return null;
        }

        $fields = collect(explode(',', (string) $this->option('fields')))
            ->map(fn (string $spec): array => $this->parseField(trim($spec)))
            ->all();

        $relations = collect($this->option('belongs-to'))
            ->map(function (string $spec): array {
                $parts = explode(':', $spec);

                return [
                    'related' => $parts[0],
                    'inverse' => in_array($parts[1] ?? '', ['has_many', 'has_one', 'none'], true) ? $parts[1] : 'has_many',
                    'nullable' => in_array('nullable', $parts, true),
                    'on_delete' => in_array('nullable', $parts, true) ? 'null' : 'cascade',
                ];
            })
            ->all();

        $seeder = filled($this->option('seeder'));

        return [
            'model' => $this->argument('name'),
            'fields' => $fields,
            'relations' => $relations,
            'soft_deletes' => (bool) $this->option('soft-deletes'),
            'factory' => (bool) $this->option('factory') || $seeder,
            'seeder' => $seeder,
            'seed_count' => (int) $this->option('seeder') ?: 10,
            'run_seeder' => ! $this->option('no-seed'),
            'policy' => (bool) $this->option('policy'),
        ];
    }

    /**
     * "price:decimal:default=0" -> field array.
     *
     * @return array<string, mixed>
     */
    protected function parseField(string $spec): array
    {
        $parts = explode(':', $spec);
        $default = collect($parts)->first(fn (string $part): bool => str_starts_with($part, 'default='));

        return [
            'name' => $parts[0],
            'type' => $parts[1] ?? 'string',
            'nullable' => in_array('nullable', $parts, true),
            'unique' => in_array('unique', $parts, true),
            'default' => $default !== null ? Str::after($default, 'default=') : null,
        ];
    }

    /**
     * Interactive prompts.
     *
     * @return array<string, mixed>
     */
    protected function askForDefinition(): array
    {
        $model = $this->argument('name') ?: text('Model name', placeholder: 'Post', required: true, hint: 'Singular, e.g. Post, BlogPost');

        $this->components->info('Fields (id, timestamps and foreign keys are added automatically)');

        $fields = [];

        do {
            $name = text('Field name', placeholder: 'title', required: true, hint: 'snake_case');
            $type = select('Type', array_combine(ModuleDefinition::FIELD_TYPES, ModuleDefinition::FIELD_TYPES), default: 'string');
            $flags = multiselect('Options', ['nullable' => 'Nullable', 'unique' => 'Unique']);
            $default = in_array($type, ModuleDefinition::DEFAULTABLE_TYPES, true)
                ? text('Default value', hint: 'Leave empty for none')
                : '';

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'nullable' => in_array('nullable', $flags, true),
                'unique' => in_array('unique', $flags, true),
                'default' => $default,
            ];
        } while (confirm('Add another field?', default: true));

        $relations = [];
        $models = ModuleDefinition::availableModels();

        while ($models !== [] && confirm('Add a belongs-to relationship?', default: false)) {
            $related = select('Belongs to', $models);
            $inverse = select(class_basename($related).' (inverse side)', ModuleDefinition::INVERSE_TYPES, default: 'has_many');
            $nullable = confirm('Nullable (optional relation)?', default: false);

            $relations[] = [
                'related' => $related,
                'inverse' => $inverse,
                'nullable' => $nullable,
                'on_delete' => select('When the '.class_basename($related).' is deleted', ModuleDefinition::ON_DELETE, default: $nullable ? 'null' : 'cascade'),
            ];
        }

        $softDeletes = confirm('Soft deletes (trash, restore, force delete)?', default: false);
        $factory = confirm('Create a factory?', default: true);
        $seeder = $factory && confirm('Create a seeder with fake data?', default: false);
        $count = $seeder ? (int) text('How many records?', default: '10', validate: fn (string $v): ?string => ctype_digit($v) && (int) $v > 0 ? null : 'Enter a positive number') : 10;
        $runSeeder = $seeder && confirm('Run the seeder now?', default: true);
        $policy = confirm('Scaffold a policy for custom rules? (not needed for permission checks)', default: false);

        return [
            'model' => $model,
            'fields' => $fields,
            'relations' => $relations,
            'soft_deletes' => $softDeletes,
            'factory' => $factory,
            'seeder' => $seeder,
            'seed_count' => $count,
            'run_seeder' => $runSeeder,
            'policy' => $policy,
        ];
    }
}
