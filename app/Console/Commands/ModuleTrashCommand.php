<?php

namespace App\Console\Commands;

use App\Support\ModuleBuilder\ModuleBuilderException;
use App\Support\ModuleBuilder\ModuleTrash;
use Illuminate\Console\Command;

class ModuleTrashCommand extends Command
{
    protected $signature = 'filalaunch:module-trash
                            {action=list : list | restore | purge | empty | prune}
                            {id? : Trash id (for restore / purge)}';

    protected $description = 'List, restore or remove deleted modules kept in storage/app/filalaunch/trash';

    public function handle(ModuleTrash $trash): int
    {
        return match ($this->argument('action')) {
            'list' => $this->list($trash),
            'restore' => $this->restore($trash),
            'purge' => $this->purge($trash),
            'empty' => $this->empty($trash),
            'prune' => $this->prune($trash),
            default => $this->invalid(),
        };
    }

    protected function list(ModuleTrash $trash): int
    {
        $entries = $trash->entries();

        if ($entries === []) {
            $this->components->info('Trash is empty.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Module', 'Table', 'Rows', 'Files', 'Size', 'Deleted', 'Removed'],
            array_map(fn (array $e): array => [$e['id'], $e['name'], $e['table'], $e['rows'], $e['file_count'], $e['size'], $e['deleted_ago'], $e['expires']], $entries),
        );

        return self::SUCCESS;
    }

    protected function restore(ModuleTrash $trash): int
    {
        if (! $id = $this->argument('id')) {
            return $this->invalid('Pass the trash id: filalaunch:module-trash restore <id>');
        }

        try {
            $warnings = $trash->restore($id);
        } catch (ModuleBuilderException $e) {
            $this->components->error('Nothing was restored:');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        $this->components->info('Module restored.');

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }

    protected function purge(ModuleTrash $trash): int
    {
        if (! ($id = $this->argument('id')) || $trash->manifest($id) === null) {
            return $this->invalid('Pass a valid trash id: filalaunch:module-trash purge <id>');
        }

        $trash->purge($id);
        $this->components->info('Backup deleted.');

        return self::SUCCESS;
    }

    protected function empty(ModuleTrash $trash): int
    {
        $this->components->info($trash->purgeAll().' backups deleted.');

        return self::SUCCESS;
    }

    protected function prune(ModuleTrash $trash): int
    {
        $this->components->info($trash->prune().' expired backups deleted.');

        return self::SUCCESS;
    }

    protected function invalid(string $message = 'Unknown action. Use: list, restore, purge, empty, prune.'): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
