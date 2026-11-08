<?php

namespace App\Support\Billing;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\PlanType;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Support\Settings;
use App\Support\Teams\Teams;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Subscription lifecycle + settings. Every state change goes through here,
 * so the admin screens, the My plan page and future gateways behave the same.
 */
class Billing
{
    /**
     * Payment gateways shown in System → Settings → Billing. Only bank transfer works today;
     * the others are listed as "Coming soon" with their key fields.
     *
     * @var array<string, array{label: string, fields: array<string, string>, secret: list<string>}>
     */
    public const GATEWAYS = [
        'stripe' => ['label' => 'Stripe', 'fields' => ['publishable_key' => 'Publishable key', 'secret_key' => 'Secret key', 'webhook_secret' => 'Webhook signing secret'], 'secret' => ['secret_key', 'webhook_secret']],
        'paypal' => ['label' => 'PayPal', 'fields' => ['client_id' => 'Client ID', 'client_secret' => 'Client secret', 'mode' => 'Mode (sandbox / live)'], 'secret' => ['client_secret']],
        'razorpay' => ['label' => 'Razorpay', 'fields' => ['key_id' => 'Key ID', 'key_secret' => 'Key secret', 'webhook_secret' => 'Webhook secret'], 'secret' => ['key_secret', 'webhook_secret']],
        'cashfree' => ['label' => 'Cashfree', 'fields' => ['app_id' => 'App ID', 'secret_key' => 'Secret key', 'mode' => 'Mode (sandbox / production)'], 'secret' => ['secret_key']],
        'kelviq' => ['label' => 'Kelviq', 'fields' => ['api_key' => 'API key', 'webhook_secret' => 'Webhook secret'], 'secret' => ['api_key', 'webhook_secret']],
        'polar' => ['label' => 'Polar.sh', 'fields' => ['access_token' => 'Access token', 'webhook_secret' => 'Webhook secret', 'organization_id' => 'Organization ID'], 'secret' => ['access_token', 'webhook_secret']],
    ];

    public const BANK_FIELDS = ['enabled', 'account_name', 'account_number', 'bank_name', 'routing_code', 'branch', 'instructions'];

    public function __construct(protected Settings $settings) {}

    // ── Settings ─────────────────────────────────────────────────────

    public static function currency(): string
    {
        return strtoupper((string) config('filalaunch.billing.currency', 'USD'));
    }

    public static function graceDays(): int
    {
        return max(0, (int) config('filalaunch.billing.grace_days', 3));
    }

    /**
     * Plan types this SaaS uses (System → Settings → Billing). Others are hidden everywhere.
     *
     * @return list<string>
     */
    public function enabledTypes(): array
    {
        $types = (array) $this->settings->get('billing.plan_types', array_column(PlanType::cases(), 'value'));

        return array_values(array_intersect(array_column(PlanType::cases(), 'value'), $types)) ?: array_column(PlanType::cases(), 'value');
    }

    /**
     * @return array<string, mixed>
     */
    public function bankTransfer(): array
    {
        return $this->settings->group('billing.bank_transfer') + array_fill_keys(self::BANK_FIELDS, null);
    }

    public function bankTransferAvailable(): bool
    {
        $bank = $this->bankTransfer();

        return (bool) $bank['enabled'] && filled($bank['account_number']);
    }

    // ── Billing owner ────────────────────────────────────────────────

    /**
     * Team that pays (team billing inside a team), or null when the user pays.
     */
    public static function ownerTeam(): ?Team
    {
        return Teams::teamBilling() ? Teams::current() : null;
    }

    /**
     * With team billing only the team's owners and admins choose, pay, renew or cancel.
     */
    public function canManageBilling(User $user): bool
    {
        $team = static::ownerTeam();

        return $team === null || $user->canManageTeam($team);
    }

    protected function assertCanManageBilling(User $user): void
    {
        if (! $this->canManageBilling($user)) {
            throw new RuntimeException('Only owners and admins can manage the '.strtolower(Teams::label()).'\'s plan.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function newSubscription(User $user, array $attributes): Subscription
    {
        return Subscription::query()->create([
            'user_id' => $user->id,
            'team_id' => static::ownerTeam()?->getKey(),
            ...$attributes,
        ]);
    }

    // ── Starting a plan ──────────────────────────────────────────────

    /**
     * Free plans and first-time trials start without a payment.
     */
    public function startsWithoutPayment(User $user, Plan $plan): bool
    {
        return $plan->isFree() || $this->trialAvailable($user, $plan);
    }

    public function trialAvailable(User $user, Plan $plan): bool
    {
        return $plan->type === PlanType::Subscription
            && $plan->trial_days > 0
            && ! $user->billableSubscriptions()->where('plan_id', $plan->id)->exists();
    }

    public function assertCanSubscribe(User $user, Plan $plan): void
    {
        if (! $plan->is_active || ! in_array($plan->type->value, $this->enabledTypes(), true)) {
            throw new RuntimeException('This plan is not available.');
        }

        $this->assertCanManageBilling($user);

        $current = $user->billableSubscriptions()->where('plan_id', $plan->id)->latest('id')->first();

        if ($current && ($current->isActive() || $current->status === SubscriptionStatus::Pending)) {
            throw new RuntimeException($current->status === SubscriptionStatus::Pending
                ? 'A payment for this plan is already waiting for approval.'
                : 'You already have this plan.');
        }
    }

    /**
     * Activate a free plan or start a trial (no payment).
     */
    public function startWithoutPayment(User $user, Plan $plan): Subscription
    {
        $this->assertCanSubscribe($user, $plan);

        if (! $this->startsWithoutPayment($user, $plan)) {
            throw new RuntimeException('This plan needs a payment.');
        }

        $now = now();

        if ($this->trialAvailable($user, $plan)) {
            $trialEnd = $now->copy()->addDays($plan->trial_days);

            return $this->newSubscription($user, [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Trialing,
                'starts_at' => $now,
                'period_starts_at' => $now,
                'trial_ends_at' => $trialEnd,
                'ends_at' => $trialEnd,
            ]);
        }

        return $this->newSubscription($user, [
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $now,
            'period_starts_at' => $now,
            'ends_at' => null, // free plans don't expire
        ]);
    }

    /**
     * Customer submitted a bank transfer for a new plan or a renewal.
     */
    public function submitBankTransfer(User $user, Plan $plan, string $reference, string $transactionId, string $proofPath, ?string $note = null, ?Subscription $renew = null): Payment
    {
        if (! $this->bankTransferAvailable()) {
            throw new RuntimeException('Bank transfer is not available.');
        }

        if ($plan->isFree()) {
            throw new RuntimeException('This plan is free.');
        }

        return DB::transaction(function () use ($user, $plan, $reference, $transactionId, $proofPath, $note, $renew): Payment {
            if ($renew) {
                $this->assertCanManageBilling($user);

                $owned = static::ownerTeam() ? $renew->team_id === static::ownerTeam()->getKey() : ($renew->user_id === $user->id && $renew->team_id === null);

                if (! $owned || ! $renew->canRenew()) {
                    throw new RuntimeException('This plan can\'t be renewed right now.');
                }

                $subscription = $renew;
                $kind = PaymentKind::Renewal;
            } else {
                $this->assertCanSubscribe($user, $plan);

                $subscription = $this->newSubscription($user, [
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Pending,
                ]);
                $kind = PaymentKind::New;
            }

            return Payment::query()->create([
                'reference' => $reference,
                'user_id' => $user->id,
                'team_id' => $subscription->team_id,
                'plan_id' => $plan->id,
                'subscription_id' => $subscription->id,
                'kind' => $kind,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'method' => 'bank_transfer',
                'status' => PaymentStatus::Pending,
                'transaction_id' => $transactionId,
                'proof_path' => $proofPath,
                'note' => $note,
            ]);
        });
    }

    // ── Admin review ─────────────────────────────────────────────────

    public function approve(Payment $payment, ?User $reviewer = null): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new RuntimeException('Only pending payments can be approved.');
        }

        return DB::transaction(function () use ($payment, $reviewer): Payment {
            $subscription = $payment->subscription;
            $plan = $payment->plan;
            $now = now();

            if ($subscription) {
                // New: period starts now. Renewal: continues from the current end (no lost days).
                $base = $payment->kind === PaymentKind::Renewal && $subscription->ends_at?->isFuture()
                    ? $subscription->ends_at->copy()
                    : $now->copy();

                $subscription->update([
                    'status' => SubscriptionStatus::Active,
                    'starts_at' => $subscription->starts_at ?? $now,
                    'period_starts_at' => $base,
                    'ends_at' => $plan->periodEnd($base),
                    'canceled_at' => null,
                ]);
            }

            $payment->update([
                'status' => PaymentStatus::Approved,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => $now,
            ]);

            return $payment;
        });
    }

    public function reject(Payment $payment, ?string $reason = null, ?User $reviewer = null): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new RuntimeException('Only pending payments can be rejected.');
        }

        return DB::transaction(function () use ($payment, $reason, $reviewer): Payment {
            if ($payment->kind === PaymentKind::New && $payment->subscription?->status === SubscriptionStatus::Pending) {
                $payment->subscription->update([
                    'status' => SubscriptionStatus::Canceled,
                    'canceled_at' => now(),
                    'ends_at' => now(),
                ]);
            }

            $payment->update([
                'status' => PaymentStatus::Rejected,
                'review_note' => $reason,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
            ]);

            return $payment;
        });
    }

    // ── Subscription changes ─────────────────────────────────────────

    /**
     * Cancel now, or at the end of the paid period (keeps access until ends_at).
     */
    public function cancel(Subscription $subscription, bool $immediately = false): Subscription
    {
        $subscription->update([
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
            'ends_at' => $immediately || $subscription->ends_at === null || $subscription->ends_at->isPast() ? now() : $subscription->ends_at,
        ]);

        return $subscription;
    }

    public function resume(Subscription $subscription): Subscription
    {
        $hasPeriod = $subscription->plan->hasPeriod() && ! $subscription->plan->isFree();

        if ($hasPeriod && ! $subscription->ends_at?->isFuture()) {
            throw new RuntimeException('The paid period is over — extend or renew instead.');
        }

        $subscription->update([
            'status' => $subscription->trial_ends_at?->isFuture() ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'canceled_at' => null,
            'ends_at' => $hasPeriod ? $subscription->ends_at : null,
        ]);

        return $subscription;
    }

    /**
     * Add one period (admin).
     */
    public function extend(Subscription $subscription): Subscription
    {
        $plan = $subscription->plan;

        if (! $plan->hasPeriod()) {
            throw new RuntimeException('This plan has no end date.');
        }

        $base = $subscription->ends_at?->isFuture() ? $subscription->ends_at->copy() : now();

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'canceled_at' => null,
            'period_starts_at' => $base,
            'ends_at' => $plan->periodEnd($base),
        ]);

        return $subscription;
    }

    /**
     * Switch plan, keeping the current dates (admin).
     */
    public function changePlan(Subscription $subscription, Plan $plan): Subscription
    {
        $subscription->update([
            'plan_id' => $plan->id,
            'ends_at' => $plan->hasPeriod() ? ($subscription->ends_at ?? $plan->periodEnd(now())) : null,
        ]);

        return $subscription;
    }

    /**
     * Tidy status labels (optional daily command): active/trialing past grace → expired, canceled past end → expired.
     */
    public function syncStatuses(): int
    {
        $expired = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->subDays(static::graceDays()))
            ->update(['status' => SubscriptionStatus::Expired->value]);

        $expired += Subscription::query()
            ->where('status', SubscriptionStatus::Canceled->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::Expired->value]);

        return $expired;
    }
}
