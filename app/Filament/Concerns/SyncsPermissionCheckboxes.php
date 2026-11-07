<?php

namespace App\Filament\Concerns;

use App\Filament\Support\PermissionCheckboxes;
use App\Models\Permission;

/**
 * For Create/Edit pages of models using Spatie's HasPermissions (Role, User).
 * Pulls the grouped checkbox state out of form data and syncs it after save.
 */
trait SyncsPermissionCheckboxes
{
    /** @var list<int>|null */
    protected ?array $pendingPermissionIds = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function pullPermissionIds(array $data): array
    {
        // Key is missing when the checkboxes are disabled (not dehydrated) — leave permissions untouched.
        if (array_key_exists(PermissionCheckboxes::STATE_PATH, $data)) {
            $this->pendingPermissionIds = PermissionCheckboxes::flatten((array) $data[PermissionCheckboxes::STATE_PATH]);
        }

        unset($data[PermissionCheckboxes::STATE_PATH]);

        return $data;
    }

    protected function syncPendingPermissions(): void
    {
        if ($this->pendingPermissionIds === null) {
            return;
        }

        $this->record->syncPermissions(Permission::query()->whereKey($this->pendingPermissionIds)->get());
    }
}
