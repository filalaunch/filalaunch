<?php

namespace App\Filament\Resources\Subscriptions\Actions;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\Billing;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Cancel / resume / extend / change plan — shared by the table and the edit page.
 */
class SubscriptionActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [static::cancel(), static::resume(), static::extend(), static::changePlan()];
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Subscription $record): bool => in_array($record->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true) && auth()->user()->can('update', $record))
            ->schema([
                Toggle::make('immediately')
                    ->label('Cancel immediately')
                    ->helperText('Off: access continues until the end of the paid period.'),
            ])
            ->action(fn (Subscription $record, array $data) => static::run(fn () => app(Billing::class)->cancel($record, (bool) $data['immediately']), 'Subscription canceled'));
    }

    public static function resume(): Action
    {
        return Action::make('resume')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Subscription $record): bool => $record->status === SubscriptionStatus::Canceled && auth()->user()->can('update', $record))
            ->action(fn (Subscription $record) => static::run(fn () => app(Billing::class)->resume($record), 'Subscription resumed'));
    }

    public static function extend(): Action
    {
        return Action::make('extend')
            ->label('Extend 1 period')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (Subscription $record): string => 'Adds one '.($record->plan->intervalLabel() ?? "{$record->plan->access_days}-day period").' from the current end date (or from today if it already ended).')
            ->visible(fn (Subscription $record): bool => $record->plan->hasPeriod() && ! $record->plan->isFree() && $record->status !== SubscriptionStatus::Pending && auth()->user()->can('update', $record))
            ->action(fn (Subscription $record) => static::run(fn () => app(Billing::class)->extend($record), 'Subscription extended'));
    }

    public static function changePlan(): Action
    {
        return Action::make('changePlan')
            ->label('Change plan')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (Subscription $record): bool => $record->status !== SubscriptionStatus::Pending && auth()->user()->can('update', $record))
            ->schema([
                Select::make('plan_id')
                    ->label('New plan')
                    ->options(fn (Subscription $record): array => Plan::query()->whereKeyNot($record->plan_id)->orderBy('sort')->get()->mapWithKeys(fn (Plan $plan): array => [$plan->id => "{$plan->name} — {$plan->priceLabel()}"])->all())
                    ->required(),
            ])
            ->action(fn (Subscription $record, array $data) => static::run(fn () => app(Billing::class)->changePlan($record, Plan::query()->findOrFail($data['plan_id'])), 'Plan changed'));
    }

    protected static function run(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
