<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\Actions\SubscriptionActions;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubscription extends EditRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([...SubscriptionActions::all(), DeleteAction::make()])->button()->label('Actions'),
        ];
    }
}
