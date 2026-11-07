<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ModuleTrashWidget;
use App\Support\ModuleBuilder\ModuleBuilder as Builder;
use App\Support\ModuleBuilder\ModuleBuilderException;
use App\Support\ModuleBuilder\ModuleRemover;
use App\Support\ModuleBuilder\ModuleTrash;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Lists every resource (builder-made or hand-written) except protected ones,
 * and deletes a module with all its files, relationships, table and permissions.
 * Deleted modules go to the trash (footer widget) and can be restored.
 */
class Modules extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Developer';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Modules';

    public static function canAccess(): bool
    {
        return Builder::enabled() && (bool) auth()->user()?->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Every resource except Users, Roles and Permissions. Deleting removes its files, relationships, table and permissions — recoverable from the trash below.';
    }

    public function mount(): void
    {
        app(ModuleTrash::class)->prune();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => app(ModuleRemover::class)->modules())
            ->paginated(false)
            ->emptyStateHeading('No modules')
            ->emptyStateDescription('Create one with the Module Builder.')
            ->columns([
                TextColumn::make('name')
                    ->label('Module')
                    ->weight('medium')
                    ->description(fn (array $record): string => $record['model']),
                TextColumn::make('table')
                    ->fontFamily('mono'),
                TextColumn::make('rows')
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('resource')
                    ->fontFamily('mono')
                    ->color('gray'),
                TextColumn::make('changes')
                    ->state(fn (array $record): string => $record['files'].' '.Str::plural('file', $record['files']).' · '.$record['edits'].' '.Str::plural('edit', $record['edits'])),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Ready' => 'success',
                        'Has dependents' => 'warning',
                        default => 'danger',
                    })
                    ->description(fn (array $record): ?string => $record['dependents'] !== [] && $record['blockers'] === [] ? 'with '.implode(', ', $record['dependents']) : null)
                    ->tooltip(fn (array $record): ?string => $record['reasons'] === [] ? null : implode("\n", $record['reasons'])),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => $record['dependents'] !== []
                        ? "Delete {$record['name']} and its dependents?"
                        : "Delete {$record['name']}?")
                    ->modalDescription(fn (array $record): HtmlString => $this->planHtml($record['model']))
                    ->modalWidth('2xl')
                    ->modalSubmitActionLabel(function (array $record): string {
                        $dependents = count(app(ModuleRemover::class)->group($record['model'])['models']) - 1;

                        return $dependents > 0 ? "Delete {$record['name']} + {$dependents} ".Str::plural('dependent', $dependents) : 'Delete module';
                    })
                    ->modalSubmitAction(fn (Action $action, array $record): Action|false => app(ModuleRemover::class)->group($record['model'])['blockers'] === [] ? $action : false)
                    ->action(function (array $record): void {
                        try {
                            $deleted = app(ModuleRemover::class)->deleteWithDependents($record['model']);
                        } catch (ModuleBuilderException $e) {
                            $this->fail($e);

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(count($deleted) > 1 ? 'Deleted '.implode(', ', $deleted) : "Module {$record['name']} deleted")
                            ->body(ModuleTrash::days() > 0 ? 'Kept in the trash for '.ModuleTrash::days().' days — restore below if needed.' : 'Deleted permanently (trash disabled).')
                            ->send();

                        $this->reload();
                    }),
            ])
            ->headerActions([
                Action::make('deleteAll')
                    ->label('Delete all')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->outlined()
                    ->visible(fn (): bool => app(ModuleRemover::class)->modules() !== [])
                    ->requiresConfirmation()
                    ->modalHeading('Delete all modules?')
                    ->modalDescription(fn (): HtmlString => $this->listHtml([
                        ...array_map(fn (array $m): string => "{$m['name']} ({$m['table']}, ".($m['rows'] ?? 0).' rows)', app(ModuleRemover::class)->modules()),
                        'Dependents are deleted first. Each module goes to the trash.',
                    ]))
                    ->schema([
                        TextInput::make('confirm')
                            ->label('Type DELETE ALL to confirm')
                            ->required()
                            ->in(['DELETE ALL'])
                            ->validationMessages(['in' => 'Type DELETE ALL exactly.']),
                    ])
                    ->modalSubmitActionLabel('Delete all')
                    ->action(function (): void {
                        try {
                            $result = app(ModuleRemover::class)->deleteAll();
                        } catch (ModuleBuilderException $e) {
                            $this->fail($e);

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(count($result['deleted']).' modules deleted')
                            ->body(implode(', ', $result['deleted']) ?: null)
                            ->send();

                        foreach ($result['blocked'] as $name => $blockers) {
                            Notification::make()->warning()->title("{$name} was not deleted")->body($this->listHtml($blockers))->persistent()->send();
                        }

                        $this->reload();
                    }),
            ]);
    }

    protected function getFooterWidgets(): array
    {
        return [ModuleTrashWidget::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function planHtml(string $model): HtmlString
    {
        $remover = app(ModuleRemover::class);
        $group = $remover->group($model);

        if ($group['blockers'] !== []) {
            return new HtmlString('<strong>Can\'t delete automatically:</strong>'.$this->listHtml($group['blockers'])->toHtml());
        }

        $html = '';
        $warnings = [];

        if (count($group['models']) > 1) {
            $html .= '<p>'.e(implode(', ', array_map('class_basename', array_slice($group['models'], 1)))).' depend on '.e(class_basename($model)).' and will be deleted first.</p>';
        }

        foreach (array_reverse($group['models']) as $member) {
            $plan = $remover->inspect($member);
            $warnings = [...$warnings, ...$plan['warnings']];

            if (count($group['models']) > 1) {
                $html .= '<p style="margin-top:.75rem"><strong>'.e($plan['name']).'</strong></p>';
            }

            $html .= $this->listHtml($remover->describe($plan))->toHtml();
        }

        if ($warnings !== []) {
            $html .= '<p style="margin-top:.75rem"><strong>Note:</strong></p>'.$this->listHtml(array_values(array_unique($warnings)))->toHtml();
        }

        $html .= '<p style="margin-top:.75rem">'.(ModuleTrash::days() > 0
            ? 'A backup goes to the trash for '.ModuleTrash::days().' days.'
            : '<strong>Trash is disabled — this can\'t be undone.</strong>').'</p>';

        return new HtmlString($html);
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

    protected function fail(ModuleBuilderException $e): void
    {
        Notification::make()->danger()->title('Nothing was deleted')->body($this->listHtml($e->errors))->persistent()->send();
    }

    /**
     * @param  list<string>  $warnings
     */
    protected function warn(array $warnings): void
    {
        foreach ($warnings as $warning) {
            Notification::make()->warning()->title($warning)->persistent()->send();
        }
    }

    /**
     * Full page load so the sidebar drops/regains menu items.
     */
    public function reload(): void
    {
        $this->redirect(static::getUrl());
    }
}
