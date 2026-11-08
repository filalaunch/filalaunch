<?php

namespace App\Filament\Pages;

use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\Billing;
use App\Support\Billing\Money;
use App\Support\Teams\Teams;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Customer page (user menu → My plan): current plans + usage, choose a plan,
 * pay by bank transfer, renew, cancel, payment history.
 */
class MyPlan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $title = 'My plan';

    protected static ?string $slug = 'my-plan';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.my-plan';

    /** Payment reference generated when the bank-transfer modal opens (server-side, not trusted from the form). */
    public ?string $reference = null;

    /**
     * Every signed-in user — in /app when the customer panel is on, otherwise in /admin.
     */
    public static function canAccess(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        $panel = rescue(fn () => Filament::getCurrentPanel()?->getId(), null, report: false);

        return Teams::appPanelEnabled() ? $panel === 'app' : $panel !== 'app';
    }

    public function getTitle(): string
    {
        return ($team = Billing::ownerTeam()) ? "{$team->name} plan" : 'My plan';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $billing = app(Billing::class);

        $subscriptions = $user->billableSubscriptions()
            ->with('plan')
            ->where(fn ($query) => $query
                ->whereIn('id', $user->activeSubscriptions()->select('id'))
                ->orWhere('status', SubscriptionStatus::Pending->value)
                ->orWhere(fn ($q) => $q->whereNotNull('ends_at')->where('ends_at', '>', now()->subDays(30))->where('status', '!=', SubscriptionStatus::Canceled->value)))
            ->latest('id')
            ->get();

        $held = $subscriptions->filter(fn (Subscription $s): bool => $s->isActive() || $s->status === SubscriptionStatus::Pending)->pluck('plan_id');

        return [
            'subscriptions' => $subscriptions,
            'plans' => Plan::query()->available()->whereNotIn('id', $held)->get(),
            'payments' => $user->billablePayments()->with('plan')->latest()->limit(20)->get(),
            'canManage' => $billing->canManageBilling($user),
            'team' => Billing::ownerTeam(),
            'usage' => $user->usageSummary(),
            'bankAvailable' => $billing->bankTransferAvailable(),
            'billing' => $billing,
        ];
    }

    // ── Actions (rendered per plan / subscription in the view) ───────

    /**
     * Free plans and first-time trials — no payment.
     */
    public function startAction(): Action
    {
        return Action::make('start')
            ->label(fn (array $arguments): string => app(Billing::class)->trialAvailable(auth()->user(), $this->plan($arguments)) ? 'Start free trial' : 'Activate')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedSparkles)
            ->modalHeading(fn (array $arguments): string => 'Activate '.$this->plan($arguments)->name.'?')
            ->modalDescription(function (array $arguments): string {
                $plan = $this->plan($arguments);

                return app(Billing::class)->trialAvailable(auth()->user(), $plan)
                    ? "Your {$plan->trial_days}-day trial starts now — no payment needed. Renew before it ends to keep access."
                    : 'This plan is free and starts right away.';
            })
            ->action(function (array $arguments): void {
                $this->attempt(fn () => app(Billing::class)->startWithoutPayment(auth()->user(), $this->plan($arguments)), 'Plan activated');
            });
    }

    /**
     * Paid plan → bank transfer with receipt.
     */
    public function payAction(): Action
    {
        return $this->bankTransferAction('pay', 'Pay by bank transfer')
            ->action(function (array $arguments, array $data): void {
                $plan = $this->plan($arguments);

                $this->attempt(fn () => app(Billing::class)->submitBankTransfer(
                    auth()->user(), $plan, $this->reference, $data['transaction_id'], $data['proof'], $data['note'] ?? null,
                ), 'Payment submitted — we\'ll activate your plan once it\'s confirmed');
            });
    }

    public function renewAction(): Action
    {
        return $this->bankTransferAction('renew', 'Renew')
            ->action(function (array $arguments, array $data): void {
                $subscription = $this->subscription($arguments);

                $this->attempt(fn () => app(Billing::class)->submitBankTransfer(
                    auth()->user(), $subscription->plan, $this->reference, $data['transaction_id'], $data['proof'], $data['note'] ?? null, $subscription,
                ), 'Renewal submitted — your plan is extended once the payment is confirmed');
            });
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->link()
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel this plan?')
            ->modalDescription(fn (array $arguments): string => ($s = $this->subscription($arguments))->ends_at?->isFuture()
                ? 'You keep access until '.$s->ends_at->toFormattedDateString().'.'
                : 'Access ends now.')
            ->action(function (array $arguments): void {
                $this->attempt(fn () => app(Billing::class)->cancel($this->subscription($arguments)), 'Plan canceled');
            });
    }

    protected function bankTransferAction(string $name, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedBuildingLibrary)
            ->modalHeading(fn (array $arguments): string => $label.': '.$this->planFromArguments($arguments)->name)
            ->modalWidth('xl')
            ->mountUsing(function (): void {
                $this->reference = Payment::newReference();
            })
            ->modalDescription(fn (array $arguments): HtmlString => $this->bankDetailsHtml($this->planFromArguments($arguments)))
            ->schema([
                TextInput::make('transaction_id')
                    ->label('Bank transaction reference / UTR')
                    ->required()
                    ->maxLength(100),
                FileUpload::make('proof')
                    ->label('Payment receipt')
                    ->helperText('Screenshot or PDF of the transfer (max 5 MB).')
                    ->required()
                    ->disk('local')
                    ->directory('payment-receipts')
                    ->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->maxSize(5120),
                Textarea::make('note')
                    ->label('Note (optional)')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Submit payment');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function plan(array $arguments): Plan
    {
        return Plan::query()->available()->findOrFail($arguments['plan'] ?? null);
    }

    protected function subscription(array $arguments): Subscription
    {
        return auth()->user()->billableSubscriptions()->with('plan')->findOrFail($arguments['subscription'] ?? null);
    }

    protected function planFromArguments(array $arguments): Plan
    {
        return isset($arguments['subscription']) ? $this->subscription($arguments)->plan : $this->plan($arguments);
    }

    protected function bankDetailsHtml(Plan $plan): HtmlString
    {
        $bank = app(Billing::class)->bankTransfer();
        $rows = [
            'Amount' => '<strong>'.e(Money::format($plan->price, $plan->currency)).'</strong>',
            'Reference' => '<strong style="font-family:monospace">'.e($this->reference).'</strong> — write it in the transfer description',
            'Account holder' => e($bank['account_name']),
            'Account number' => '<span style="font-family:monospace">'.e($bank['account_number']).'</span>',
            'Bank' => e($bank['bank_name']),
            'IFSC / SWIFT' => e($bank['routing_code']),
            'Branch' => e($bank['branch']),
        ];

        $html = '<table style="width:100%;text-align:left;margin-top:.5rem;border-collapse:collapse">';

        foreach (array_filter($rows, fn ($value): bool => filled(strip_tags((string) $value))) as $label => $value) {
            $html .= '<tr><td style="padding:.25rem .75rem .25rem 0;color:rgb(107 114 128);white-space:nowrap;vertical-align:top">'.e($label).'</td><td style="padding:.25rem 0">'.$value.'</td></tr>';
        }

        $html .= '</table>';

        if (filled($bank['instructions'])) {
            $html .= '<p style="margin-top:.75rem;text-align:left">'.nl2br(e($bank['instructions'])).'</p>';
        }

        return new HtmlString($html);
    }

    protected function attempt(callable $callback, string $success): void
    {
        try {
            $callback();
            auth()->user()->flushPlanCache();
            Notification::make()->success()->title($success)->send();
            $this->redirect(static::getUrl()); // full reload: menu reflects newly unlocked modules
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
