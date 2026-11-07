<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Concerns\SyncsPermissionCheckboxes;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Support\PermissionCheckboxes;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    use SyncsPermissionCheckboxes;

    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! $this->record->subscriptions()->exists())
                ->tooltip('Plans with subscriptions can only be deactivated.'),
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
