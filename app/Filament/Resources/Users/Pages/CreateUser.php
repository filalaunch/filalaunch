<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\SyncsPermissionCheckboxes;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use SyncsPermissionCheckboxes;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->pullPermissionIds($data);
    }

    protected function afterCreate(): void
    {
        $this->syncPendingPermissions();
    }
}
