<?php

namespace App\Console\Commands;

use App\Support\Permissions\PermissionSync;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'filalaunch:sync-permissions
                            {--panel=admin : Filament panel ID to scan for resources}
                            {--policies : Also generate missing policy classes from stubs/filalaunch-policy.stub}';

    protected $description = 'Create standard permissions ({resource}.{ability}) for every Filament resource';

    public function handle(PermissionSync $sync, Filesystem $files): int
    {
        $panel = (string) $this->option('panel');

        $result = $sync->sync($panel);

        if ($result['created'] === []) {
            $this->components->info('Permissions are up to date ('.count($result['existing']).' existing).');
        } else {
            $this->components->info(count($result['created']).' permission(s) created:');
            $this->components->bulletList($result['created']);
        }

        if ($this->option('policies')) {
            $this->generatePolicies($sync, $files, $panel);
        }

        return self::SUCCESS;
    }

    protected function generatePolicies(PermissionSync $sync, Filesystem $files, string $panel): void
    {
        $stub = $files->get(base_path('stubs/filalaunch-policy.stub'));

        foreach (array_unique($sync->resourceModels($panel)) as $model) {
            if (! Str::startsWith($model, 'App\\Models\\')) {
                $this->components->warn("Skipped [{$model}]: policies are only generated for App\\Models.");

                continue;
            }

            $name = class_basename($model);
            $path = app_path("Policies/{$name}Policy.php");

            if ($files->exists($path)) {
                $this->components->twoColumnDetail("{$name}Policy", '<fg=gray>exists</>');

                continue;
            }

            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, str_replace(
                ['{{ model }}', '{{ prefix }}'],
                [$name, PermissionSync::prefixFor($model)],
                $stub,
            ));

            $this->components->twoColumnDetail("{$name}Policy", '<fg=green>created</>');
        }
    }
}
