<?php

namespace App\Filament\Support;

use App\Models\Permission;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Permission checkboxes grouped by prefix ("users.*", "roles.*", ...).
 * Used by both the Role form and the User form (direct permissions).
 *
 * State shape: ['permission_ids' => ['users' => [1, 2], 'roles' => [7]]]
 */
class PermissionCheckboxes
{
    public const STATE_PATH = 'permission_ids';

    /**
     * @return array<Component>
     */
    public static function make(bool|Closure $disabled = false): array
    {
        $groups = Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission): string => $permission->getGroup());

        if ($groups->isEmpty()) {
            return [
                Text::make('No permissions yet. Run `php artisan filalaunch:sync-permissions` or create one under Permissions.'),
            ];
        }

        return $groups
            ->map(fn ($permissions, string $group) => Fieldset::make(Str::headline($group))
                ->schema([
                    CheckboxList::make(static::STATE_PATH.'.'.$group)
                        ->hiddenLabel()
                        ->options($permissions->mapWithKeys(fn (Permission $permission): array => [
                            $permission->id => Str::headline(Str::after($permission->name, '.')),
                        ])->all())
                        ->bulkToggleable()
                        ->columns(['default' => 2, 'md' => 3, 'xl' => 5])
                        ->gridDirection('row')
                        ->columnSpanFull()
                        ->disabled($disabled),
                ])
                ->columnSpanFull())
            ->values()
            ->all();
    }

    /**
     * Current permissions of a Role / User, in form state shape.
     *
     * @return array<string, list<int>>
     */
    public static function stateFor(Model $record): array
    {
        return $record->permissions()
            ->get()
            ->groupBy(fn (Permission $permission): string => $permission->getGroup())
            ->map(fn ($permissions) => $permissions->pluck('id')->all())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<int>
     */
    public static function flatten(array $state): array
    {
        return collect($state)->flatten()->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }
}
