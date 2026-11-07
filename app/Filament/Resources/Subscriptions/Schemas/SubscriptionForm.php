<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Subscription')
                    ->description('Assign a plan to a user manually — e.g. after an offline payment, or as a gift.')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('User')
                            ->relationship('user', 'email')
                            ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->name} ({$record->email})")
                            ->searchable(['name', 'email'])
                            ->preload()
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('plan_id')
                            ->label('Plan')
                            ->relationship('plan', 'name')
                            ->getOptionLabelFromRecordUsing(fn (Plan $record): string => "{$record->name} — {$record->priceLabel()}")
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                $plan = $state ? Plan::query()->find($state) : null;
                                $start = Carbon::parse($get('starts_at') ?: now());
                                $set('ends_at', $plan && ! $plan->isFree() ? $plan->periodEnd($start)?->toDateTimeString() : null);
                            }),
                        Select::make('status')
                            ->options(SubscriptionStatus::class)
                            ->default(SubscriptionStatus::Active->value)
                            ->required(),
                        DateTimePicker::make('starts_at')
                            ->default(now())
                            ->required(),
                        DateTimePicker::make('trial_ends_at')
                            ->label('Trial ends'),
                        DateTimePicker::make('ends_at')
                            ->label('Ends')
                            ->helperText('Empty = never ends (lifetime / free). Filled automatically from the plan.'),
                    ]),
            ]);
    }
}
