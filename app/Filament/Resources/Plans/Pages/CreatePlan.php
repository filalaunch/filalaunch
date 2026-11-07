<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Concerns\SyncsPermissionCheckboxes;
use App\Filament\Resources\Plans\PlanResource;
use App\Support\Billing\Billing;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    use SyncsPermissionCheckboxes;

    protected static string $resource = PlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['currency'] = Billing::currency();

        return $this->pullPermissionIds($data);
    }

    protected function afterCreate(): void
    {
        $this->syncPendingPermissions();
    }
}
