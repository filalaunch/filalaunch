<?php

namespace App\Console\Commands;

use App\Support\ModuleBuilder\ModuleBuilder;
use App\Support\ModuleBuilder\ModuleBuilderException;
use App\Support\ModuleBuilder\ModuleRemover;
use App\Support\ModuleBuilder\ModuleTrash;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class DeleteModuleCommand extends Command
{
    protected $signature = 'filalaunch:delete-module
                            {name? : Model name, e.g. Post}
                            {--all : Delete every module (dependents first)}
                            {--force : Skip the confirmation}';

    protected $description = 'Delete a module: resource, model, factory, seeder, policy, migrations, relationships, table and permissions (kept in the trash)';

    public function handle(ModuleRemover $remover): int
    {
        if (! ModuleBuilder::enabled()) {
            $this->components->error('Module Builder is disabled. Set FILALAUNCH_BUILDER_ENABLED=true in .env to enable it.');

            return self::FAILURE;
        }

        $modules = $remover->modules();

        if ($modules === []) {
            $this->components->info('No modules to delete (Users, Roles and Permissions are protected).');

            return self::SUCCESS;
        }

        return $this->option('all') ? $this->deleteAll($remover, $modules) : $this->deleteOne($remover, $modules);
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     */
    protected function deleteOne(ModuleRemover $remover, array $modules): int
    {
        $name = $this->argument('name');

        if (blank($name)) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Pass a module name or --all.');

                return self::FAILURE;
            }

            $name = select('Which module?', collect($modules)->mapWithKeys(fn (array $m): array => [
                $m['name'] => "{$m['name']} — {$m['table']}, ".($m['rows'] ?? 0)." rows · {$m['status']}",
            ])->all());
        }

        $module = $modules[$name] ?? collect($modules)->first(fn (array $m): bool => strcasecmp($m['name'], (string) $name) === 0);

        if ($module === null) {
            $this->components->error("Module [{$name}] not found. Available: ".implode(', ', array_keys($modules)));

            return self::FAILURE;
        }

        $plan = $remover->inspect($module['model']);

        if ($plan['blockers'] !== []) {
            $this->components->error("{$module['name']} can't be deleted yet:");
            $this->components->bulletList($plan['blockers']);

            return self::FAILURE;
        }

        $this->components->warn("Deleting {$module['name']} will:");
        $this->components->bulletList($remover->describe($plan));

        foreach ($plan['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        if (! $this->confirmed("Delete {$module['name']}?")) {
            return self::FAILURE;
        }

        try {
            $result = $remover->delete($module['model']);
        } catch (ModuleBuilderException $e) {
            $this->components->error('Nothing was deleted:');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        $this->components->info("Module {$module['name']} deleted.");

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        $this->trashHint($result['trash']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     */
    protected function deleteAll(ModuleRemover $remover, array $modules): int
    {
        $this->components->warn('This deletes every module below (dependents first):');
        $this->components->bulletList(array_map(fn (array $m): string => "{$m['name']} ({$m['table']}, ".($m['rows'] ?? 0).' rows)', $modules));

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Use --force with --no-interaction.');

                return self::FAILURE;
            }

            if (text('Type DELETE ALL to confirm') !== 'DELETE ALL') {
                $this->components->info('Cancelled.');

                return self::FAILURE;
            }
        }

        try {
            $result = $remover->deleteAll();
        } catch (ModuleBuilderException $e) {
            $this->components->error('Stopped:');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        $this->components->info(count($result['deleted']).' modules deleted: '.implode(', ', $result['deleted']));

        foreach ($result['blocked'] as $name => $blockers) {
            $this->components->warn("{$name} was not deleted:");
            $this->components->bulletList($blockers);
        }

        $this->trashHint(ModuleTrash::days() > 0 ? 'all' : null);

        return self::SUCCESS;
    }

    protected function confirmed(string $question): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Use --force with --no-interaction.');

            return false;
        }

        return confirm($question, default: false);
    }

    protected function trashHint(?string $trashId): void
    {
        if ($trashId === null) {
            $this->components->warn('Trash is disabled — this was permanent.');

            return;
        }

        $this->components->info('Kept in the trash for '.ModuleTrash::days().' days. Restore with `php artisan filalaunch:module-trash restore'.($trashId !== 'all' ? " {$trashId}" : ' <id>').'` or from Developer → Modules.');
    }
}
