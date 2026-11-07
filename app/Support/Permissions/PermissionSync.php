<?php

namespace App\Support\Permissions;

use App\Models\Permission;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Generates "{prefix}.{ability}" permissions for every Filament resource.
 *
 * e.g. App\Models\User      -> users.view_any, users.view, users.create ...
 *      App\Models\BlogPost  -> blog_posts.view_any, ...
 */
class PermissionSync
{
    /**
     * Permission prefix used for a model class.
     */
    public static function prefixFor(string $modelClass): string
    {
        return Str::of(class_basename($modelClass))->snake()->plural()->toString();
    }

    /**
     * @return list<string>
     */
    public static function abilitiesFor(string $modelClass): array
    {
        $abilities = config('filalaunch.permissions.abilities', []);

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $abilities = [...$abilities, ...config('filalaunch.permissions.soft_delete_abilities', [])];
        }

        return $abilities;
    }

    /**
     * Model classes of all resources registered in the given panel.
     *
     * @return array<class-string, class-string> resource => model
     */
    public function resourceModels(string $panelId = 'admin'): array
    {
        $models = [];

        foreach (Filament::getPanel($panelId)->getResources() as $resource) {
            $models[$resource] = $resource::getModel();
        }

        return $models;
    }

    /**
     * Create any missing permissions. Never deletes existing ones.
     *
     * @return array{created: list<string>, existing: list<string>}
     */
    public function sync(string $panelId = 'admin', string $guard = 'web'): array
    {
        $result = ['created' => [], 'existing' => []];

        foreach (array_unique($this->resourceModels($panelId)) as $model) {
            $prefix = static::prefixFor($model);

            foreach (static::abilitiesFor($model) as $ability) {
                $permission = Permission::firstOrCreate([
                    'name' => "{$prefix}.{$ability}",
                    'guard_name' => $guard,
                ]);

                $result[$permission->wasRecentlyCreated ? 'created' : 'existing'][] = $permission->name;
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $result;
    }
}
