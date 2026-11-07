<?php

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Resources\Permissions\PermissionResource;
use App\Models\Permission;
use App\Support\Permissions\PermissionSync;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncPermissions')
                ->label('Sync from resources')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->tooltip('Create missing view_any / view / create / update / delete permissions for every resource')
                ->authorize(fn (): bool => auth()->user()->can('create', Permission::class))
                ->action(function (PermissionSync $sync): void {
                    $created = $sync->sync()['created'];

                    Notification::make()
                        ->success()
                        ->title($created === [] ? 'Permissions are up to date' : count($created).' permission(s) created')
                        ->body($created === [] ? null : implode(', ', $created))
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
