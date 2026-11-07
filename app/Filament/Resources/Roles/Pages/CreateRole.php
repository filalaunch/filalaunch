<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\SyncsPermissionCheckboxes;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use SyncsPermissionCheckboxes;

    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->pullPermissionIds($data);
    }

    protected function afterCreate(): void
    {
        $this->syncPendingPermissions();
    }
}
