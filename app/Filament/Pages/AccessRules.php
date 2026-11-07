<?php

namespace App\Filament\Pages;

use App\Enums\AccessMode;
use App\Models\Plan;
use App\Support\Billing\ModuleAccess;
use App\Support\ModuleBuilder\ModuleRemover;
use App\Support\Permissions\PermissionSync;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Access mode per module: role/permission (default), plan, or either.
 * Kit modules (users, roles, billing…) are always role/permission and aren't listed.
 */
class AccessRules extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Access rules';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Choose how each module is unlocked. "Plan" modules are opened by the module access you tick on each plan. Super Admin always has access.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->modules())
            ->paginated(false)
            ->emptyStateHeading('No modules yet')
            ->emptyStateDescription('Modules you build appear here automatically.')
            ->columns([
                TextColumn::make('name')
                    ->label('Module')
                    ->weight('medium')
                    ->description(fn (array $record): string => $record['prefix'].'.*'),
                SelectColumn::make('mode')
                    ->label('Access mode')
                    ->options(collect(AccessMode::cases())->mapWithKeys(fn (AccessMode $mode): array => [$mode->value => $mode->getLabel()])->all())
                    ->selectablePlaceholder(false)
                    ->updateStateUsing(function (string $state, array $record): string {
                        app(ModuleAccess::class)->setMode($record['prefix'], AccessMode::from($state));
                        Notification::make()->success()->title("{$record['name']}: ".AccessMode::from($state)->getLabel())->send();

                        return $state;
                    }),
                TextColumn::make('plans')
                    ->label('Plans that include it')
                    ->badge()
                    ->color('gray')
                    ->placeholder('None'),
            ]);
    }

    /**
     * @return array<string, array{key: string, prefix: string, name: string, mode: string, plans: list<string>}>
     */
    protected function modules(): array
    {
        $access = app(ModuleAccess::class);
        $kit = ModuleAccess::kitPrefixes();
        $modules = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $model = $resource::getModel();

            if (in_array($model, ModuleRemover::protectedModels(), true)) {
                continue;
            }

            $prefix = PermissionSync::prefixFor($model);

            if (in_array($prefix, $kit, true) || isset($modules[$prefix])) {
                continue;
            }

            $modules[$prefix] = [
                'key' => $prefix,
                'prefix' => $prefix,
                'name' => $resource::getPluralModelLabel() ? str($resource::getPluralModelLabel())->headline()->toString() : class_basename($model),
                'mode' => $access->mode($prefix)->value,
                'plans' => $this->plansFor($prefix),
            ];
        }

        ksort($modules);

        return $modules;
    }

    /**
     * @return list<string>
     */
    protected function plansFor(string $prefix): array
    {
        return rescue(fn () => Plan::query()
            ->whereIn('id', DB::table('plan_permission')
                ->join('permissions', 'permissions.id', '=', 'plan_permission.permission_id')
                ->where('permissions.name', 'like', $prefix.'.%')
                ->select('plan_permission.plan_id'))
            ->orderBy('sort')
            ->pluck('name')
            ->all(), [], report: false);
    }
}
