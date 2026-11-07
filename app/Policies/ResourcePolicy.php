<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Billing\ModuleAccess;
use App\Support\Permissions\PermissionSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Base policy: every ability maps to a "{prefix}.{ability}" permission.
 *
 * Extend it only when a model needs extra rules (see UserPolicy, RolePolicy).
 * Models without their own policy are guarded by DefaultPolicy automatically.
 *
 * Every ability Filament checks is a real method here, because Filament uses
 * method_exists() — a missing method would mean "allowed".
 */
abstract class ResourcePolicy
{
    /**
     * Model guarded by this policy. Defaults to App\Models\{Name} from "{Name}Policy".
     *
     * @var class-string<Model>|null
     */
    protected ?string $model = null;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'viewAny');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->allows($user, 'replicate');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'update');
    }

    public function reorder(User $user): bool
    {
        return $this->allows($user, 'reorder');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'deleteAny');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->allows($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->allows($user, 'restoreAny');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->allows($user, 'forceDelete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->allows($user, 'forceDeleteAny');
    }

    public function attach(User $user, Model $record): bool
    {
        return $this->allows($user, 'attach');
    }

    public function attachAny(User $user): bool
    {
        return $this->allows($user, 'attachAny');
    }

    public function detach(User $user, Model $record): bool
    {
        return $this->allows($user, 'detach');
    }

    public function detachAny(User $user): bool
    {
        return $this->allows($user, 'detachAny');
    }

    public function associate(User $user, Model $record): bool
    {
        return $this->allows($user, 'associate');
    }

    public function associateAny(User $user): bool
    {
        return $this->allows($user, 'associateAny');
    }

    public function dissociate(User $user, Model $record): bool
    {
        return $this->allows($user, 'dissociate');
    }

    public function dissociateAny(User $user): bool
    {
        return $this->allows($user, 'dissociateAny');
    }

    /**
     * Custom abilities, e.g. $user->can('export', Project::class) -> "projects.export".
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): bool
    {
        $user = $arguments[0] ?? null;

        return $user instanceof User && $this->allows($user, $method);
    }

    /**
     * Permission check through the module's access mode (role/permission, plan, or either).
     */
    protected function allows(User $user, string $ability): bool
    {
        return app(ModuleAccess::class)->allows($user, PermissionSync::permissionFor($this->modelClass(), $ability));
    }

    /**
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return $this->model ?? 'App\\Models\\'.Str::beforeLast(class_basename(static::class), 'Policy');
    }
}
