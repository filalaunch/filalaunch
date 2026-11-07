<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\SyncsPermissionCheckboxes;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\PermissionCheckboxes;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    use SyncsPermissionCheckboxes;

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data[PermissionCheckboxes::STATE_PATH] = PermissionCheckboxes::stateFor($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->pullPermissionIds($data);
    }

    protected function afterSave(): void
    {
        $this->syncPendingPermissions();
    }
}
