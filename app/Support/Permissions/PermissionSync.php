<?php

namespace App\Support\Permissions;

use App\Models\Permission;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Generates "{prefix}.{ability}" permissions for every Filament resource.
 *
 * e.g. App\Models\User      -> users.view_any, users.view, users.create ...
 *      App\Models\BlogPost  -> blog_posts.view_any, ...
 *
 * Permissions are only created — never assigned and never deleted.
 */
class PermissionSync
{
    /**
     * Policy ability (method name) => permission ability.
     * Abilities not listed here map to their snake_case name (e.g. "export" -> "export").
     */
    public const ABILITY_MAP = [
        'viewAny' => 'view_any',
        'view' => 'view',
        'create' => 'create',
        'replicate' => 'create',
        'update' => 'update',
        'reorder' => 'update',
        'attach' => 'update',
        'attachAny' => 'update',
        'detach' => 'update',
        'detachAny' => 'update',
        'associate' => 'update',
        'associateAny' => 'update',
        'dissociate' => 'update',
        'dissociateAny' => 'update',
        'delete' => 'delete',
        'deleteAny' => 'delete',
        'restore' => 'restore',
        'restoreAny' => 'restore',
        'forceDelete' => 'force_delete',
        'forceDeleteAny' => 'force_delete',
    ];

    /**
     * Permission prefix used for a model class.
     */
    public static function prefixFor(string $modelClass): string
    {
        return Str::of(class_basename($modelClass))->snake()->plural()->toString();
    }

    /**
     * Permission name that guards a policy ability on a model, e.g. (User, 'viewAny') -> "users.view_any".
     */
    public static function permissionFor(string $modelClass, string $ability): string
    {
        return static::prefixFor($modelClass).'.'.(static::ABILITY_MAP[$ability] ?? Str::snake($ability));
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
     * Every permission name the panel's resources need.
     *
     * @return list<string>
     */
    public function expectedPermissions(string $panelId = 'admin'): array
    {
        $names = [];

        foreach (array_unique($this->resourceModels($panelId)) as $model) {
            $prefix = static::prefixFor($model);

            foreach (static::abilitiesFor($model) as $ability) {
                $names[] = "{$prefix}.{$ability}";
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Create any missing permissions. Never deletes or assigns.
     *
     * @return array{created: list<string>, existing: list<string>}
     */
    public function sync(string $panelId = 'admin', string $guard = 'web'): array
    {
        $result = ['created' => [], 'existing' => []];

        foreach ($this->expectedPermissions($panelId) as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);

            $result[$permission->wasRecentlyCreated ? 'created' : 'existing'][] = $permission->name;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $result;
    }

    /**
     * Cheap check run on every panel request: compares the needed permissions with
     * Spatie's cached permission list and only syncs when something is missing.
     */
    public function ensureSynced(string $panelId = 'admin', string $guard = 'web'): void
    {
        try {
            $existing = app(PermissionRegistrar::class)
                ->getPermissions(['guard_name' => $guard])
                ->pluck('name')
                ->all();

            if (array_diff($this->expectedPermissions($panelId), $existing) !== []) {
                $this->sync($panelId, $guard);
            }
        } catch (QueryException) {
            // Database not migrated yet (fresh install) — nothing to sync.
        }
    }
}
