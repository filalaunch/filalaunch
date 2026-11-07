<?php

namespace App\Support\Billing;

use App\Enums\AccessMode;
use App\Models\ModuleAccessRule;
use App\Models\User;
use App\Support\ModuleBuilder\ModuleRemover;
use App\Support\Permissions\PermissionSync;
use Illuminate\Support\Str;

/**
 * The single place that decides module access (called by ResourcePolicy and DefaultPolicy).
 *
 *   permission → roles + direct permissions (default for every module)
 *   plan       → only an active plan that includes the permission
 *   either     → role/permission OR active plan
 *
 * Super Admin always passes. Safety rules in custom policies run before this.
 */
class ModuleAccess
{
    /** @var array<string, AccessMode>|null */
    protected ?array $rules = null;

    public function allows(User $user, string $permission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($this->mode(Str::before($permission, '.'))) {
            AccessMode::Permission => $user->can($permission),
            AccessMode::Plan => $user->planAllows($permission),
            AccessMode::Either => $user->can($permission) || $user->planAllows($permission),
        };
    }

    /**
     * Permission prefixes of kit modules (users, roles, billing…). Their access mode is
     * always role/permission and plans can't grant them.
     *
     * @return list<string>
     */
    public static function kitPrefixes(): array
    {
        return array_values(array_unique(array_map(
            fn (string $model): string => PermissionSync::prefixFor($model),
            ModuleRemover::protectedModels(),
        )));
    }

    public function mode(string $prefix): AccessMode
    {
        $this->rules ??= rescue(
            fn () => ModuleAccessRule::query()->get()->mapWithKeys(fn (ModuleAccessRule $rule): array => [$rule->prefix => $rule->mode])->all(),
            [],
            report: false,
        );

        if (in_array($prefix, static::kitPrefixes(), true)) {
            return AccessMode::Permission;
        }

        return $this->rules[$prefix] ?? AccessMode::Permission;
    }

    public function setMode(string $prefix, AccessMode $mode): void
    {
        if ($mode === AccessMode::Permission) {
            ModuleAccessRule::query()->where('prefix', $prefix)->delete();
        } else {
            ModuleAccessRule::query()->updateOrCreate(['prefix' => $prefix], ['mode' => $mode]);
        }

        $this->rules = null;
    }
}
