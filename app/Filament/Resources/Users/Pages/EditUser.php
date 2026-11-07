<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\SyncsPermissionCheckboxes;
use App\Filament\Resources\Users\Actions\ResetTwoFactorAction;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\PermissionCheckboxes;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use SyncsPermissionCheckboxes;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ResetTwoFactorAction::make(),
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
