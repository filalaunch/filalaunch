<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Enums\BillingInterval;
use App\Enums\LimitBehavior;
use App\Enums\MeterReset;
use App\Enums\PlanType;
use App\Filament\Support\PermissionCheckboxes;
use App\Models\Meter;
use App\Support\Billing\Billing;
use App\Support\Billing\ModuleAccess;
use App\Support\Billing\Money;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Billing::currency();
        $isType = fn (PlanType ...$types): \Closure => fn (Get $get): bool => in_array(
            $get('type') instanceof PlanType ? $get('type') : PlanType::tryFrom((string) $get('type')),
            $types,
            true,
        );

        return $schema
            ->columns(1)
            ->components([
                Section::make('Plan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(100)
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                        ToggleButtons::make('type')
                            ->options(fn (): array => collect(PlanType::cases())
                                ->filter(fn (PlanType $type): bool => in_array($type->value, app(Billing::class)->enabledTypes(), true))
                                ->mapWithKeys(fn (PlanType $type): array => [$type->value => $type->getLabel()])
                                ->all())
                            ->default(fn (): string => app(Billing::class)->enabledTypes()[0])
                            ->required()
                            ->inline()
                            ->live()
                            ->helperText('Only the plan types enabled in Billing settings are shown.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Pricing')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextInput::make('price')
                            ->label(fn (Get $get): string => $isType(PlanType::Usage)($get) ? 'Base price' : 'Price')
                            ->prefix($currency)
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->required()
                            ->formatStateUsing(fn ($state): ?string => Money::fromMinor($state === null ? null : (int) $state))
                            ->dehydrateStateUsing(fn ($state): int => Money::toMinor($state))
                            ->helperText('0 = free.'),
                        Select::make('interval')
                            ->label(fn (Get $get): string => $isType(PlanType::Usage)($get) ? 'Base price & cycle every' : 'Billed every')
                            ->options(BillingInterval::class)
                            ->default(BillingInterval::Month->value)
                            ->required(fn (Get $get): bool => $isType(PlanType::Subscription)($get))
                            ->visible($isType(PlanType::Subscription, PlanType::Usage)),
                        TextInput::make('interval_count')
                            ->label('Interval count')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->helperText('e.g. 3 + Month = quarterly.')
                            ->visible($isType(PlanType::Subscription, PlanType::Usage)),
                        TextInput::make('trial_days')
                            ->label('Trial days')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Trial starts without payment (once per user).')
                            ->visible($isType(PlanType::Subscription)),
                        TextInput::make('access_days')
                            ->label('Access period (days)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->placeholder('Lifetime')
                            ->helperText('Leave empty for lifetime access.')
                            ->visible($isType(PlanType::OneTime)),
                    ]),

                Section::make('Usage meters')
                    ->description('Included quantity per period, price per extra unit, and what happens at the limit.')
                    ->visible($isType(PlanType::Usage))
                    ->schema([
                        Repeater::make('planMeters')
                            ->hiddenLabel()
                            ->relationship()
                            ->schema([
                                Select::make('meter_id')
                                    ->label('Meter')
                                    ->options(fn (): array => Meter::query()->orderBy('name')->pluck('name', 'id')->all())
                                    ->required()
                                    ->distinct()
                                    ->createOptionForm([
                                        TextInput::make('name')->required(),
                                        TextInput::make('key')->required()->alphaDash()->unique('meters', 'key')->helperText('Used in code: $user->recordUsage(\'api_calls\')'),
                                        TextInput::make('unit')->default('unit')->required(),
                                        Select::make('reset')->options(MeterReset::class)->default('monthly')->required(),
                                    ])
                                    ->createOptionUsing(fn (array $data): int => Meter::query()->create($data)->id),
                                TextInput::make('included')
                                    ->label('Included per period')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->placeholder('Unlimited'),
                                TextInput::make('unit_price')
                                    ->label('Price per extra unit')
                                    ->prefix($currency)
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->default(0)
                                    ->formatStateUsing(fn ($state): ?string => Money::fromMinor($state === null ? null : (int) $state))
                                    ->dehydrateStateUsing(fn ($state): int => Money::toMinor($state)),
                                Select::make('on_limit')
                                    ->label('At the limit')
                                    ->options(LimitBehavior::class)
                                    ->default(LimitBehavior::Block->value)
                                    ->required(),
                            ])
                            ->columns(['default' => 1, 'md' => 4])
                            ->defaultItems(0)
                            ->addActionLabel('Add meter'),
                    ]),

                Section::make('Module access')
                    ->description('What a subscriber of this plan can do. Applies to modules whose access mode is "Plan" or "Role / permission or plan" (Billing → Access rules).')
                    ->collapsible()
                    ->schema(fn (): array => PermissionCheckboxes::make(exceptGroups: ModuleAccess::kitPrefixes())),

                Section::make('Display')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        Repeater::make('features')
                            ->label('Feature bullets')
                            ->simple(TextInput::make('feature')->required()->maxLength(120))
                            ->defaultItems(0)
                            ->addActionLabel('Add feature')
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active (customers can choose it)')
                            ->default(true),
                        Toggle::make('is_featured')
                            ->label('Featured'),
                        TextInput::make('sort')
                            ->numeric()
                            ->integer()
                            ->default(0),
                    ]),
            ]);
    }
}
