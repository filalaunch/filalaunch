<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Modules;
use App\Support\ModuleBuilder\ModuleBuilder as Builder;
use App\Support\ModuleBuilder\ModuleBuilderException;
use App\Support\ModuleBuilder\ModuleTrash;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;

/**
 * Trash of deleted modules, shown under the Modules page (never on the dashboard).
 */
class ModuleTrashWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Builder::enabled() && (bool) auth()->user()?->isSuperAdmin();
    }

    public function table(Table $table): Table
    {
        $days = ModuleTrash::days();

        return $table
            ->heading('Trash')
            ->description($days > 0
                ? "Deleted modules are kept for {$days} days, then removed automatically to free up space."
                : 'Trash is disabled (FILALAUNCH_TRASH_DAYS=0) — deletes are permanent.')
            ->records(fn (): array => app(ModuleTrash::class)->entries())
            ->paginated(false)
            ->emptyStateHeading('Trash is empty')
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox)
            ->columns([
                TextColumn::make('name')
                    ->label('Module')
                    ->weight('medium')
                    ->description(fn (array $record): string => (string) $record['table']),
                TextColumn::make('deleted_ago')
                    ->label('Deleted'),
                TextColumn::make('file_count')
                    ->label('Files'),
                TextColumn::make('rows')
                    ->numeric(),
                TextColumn::make('size'),
                TextColumn::make('expires')
                    ->label('Removed')
                    ->color('gray'),
            ])
            ->recordActions([
                Action::make('restore')
                    ->label('Restore')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => "Restore {$record['name']}?")
                    ->modalDescription(fn (array $record): HtmlString => $this->restoreHtml($record))
                    ->modalSubmitAction(fn (Action $action, array $record): Action|false => app(ModuleTrash::class)->conflicts($record['id']) === [] ? $action : false)
                    ->modalSubmitActionLabel('Restore')
                    ->action(function (array $record): void {
                        try {
                            $warnings = app(ModuleTrash::class)->restore($record['id']);
                        } catch (ModuleBuilderException $e) {
                            Notification::make()->danger()->title('Nothing was restored')->body($this->listHtml($e->errors))->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title("Module {$record['name']} restored")->send();

                        foreach ($warnings as $warning) {
                            Notification::make()->warning()->title($warning)->persistent()->send();
                        }

                        $this->redirect(Modules::getUrl());
                    }),
                Action::make('purge')
                    ->label('Delete forever')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => "Delete {$record['name']} backup forever?")
                    ->modalDescription('The backup files and table data are removed from disk. This can\'t be undone.')
                    ->action(function (array $record): void {
                        app(ModuleTrash::class)->purge($record['id']);
                        Notification::make()->success()->title('Backup deleted')->send();

                        $this->redirect(Modules::getUrl());
                    }),
            ])
            ->headerActions([
                Action::make('empty')
                    ->label('Empty trash')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('gray')
                    ->visible(fn (): bool => app(ModuleTrash::class)->entries() !== [])
                    ->requiresConfirmation()
                    ->modalDescription('All backups are removed from disk. This can\'t be undone.')
                    ->action(function (): void {
                        $count = app(ModuleTrash::class)->purgeAll();
                        Notification::make()->success()->title("{$count} backups deleted")->send();

                        $this->redirect(Modules::getUrl());
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function restoreHtml(array $record): HtmlString
    {
        $conflicts = app(ModuleTrash::class)->conflicts($record['id']);

        if ($conflicts !== []) {
            return new HtmlString('<strong>Can\'t restore yet:</strong>'.$this->listHtml($conflicts)->toHtml());
        }

        return $this->listHtml(array_filter([
            "{$record['file_count']} files back to their original place",
            ...array_map(fn (array $edit): string => str_replace(['Remove ', ' from '], ['Put back ', ' in '], $edit['description']), $record['edits']),
            $record['migrations'] !== [] ? "Recreate table {$record['table']}" : null,
            $record['rows'] ? "Restore {$record['rows']} rows" : null,
            $record['permissions'] !== [] ? count($record['permissions']).' permissions with their role and user assignments' : null,
        ]));
    }

    /**
     * @param  list<string>  $items
     */
    protected function listHtml(array $items): HtmlString
    {
        return new HtmlString(
            '<ul style="list-style:disc;padding-left:1.25rem;text-align:left;margin-top:.5rem">'
            .collect($items)->map(fn (string $item): string => '<li>'.e($item).'</li>')->implode('')
            .'</ul>'
        );
    }
}
