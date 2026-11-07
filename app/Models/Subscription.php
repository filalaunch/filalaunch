<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Support\Billing\Billing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    protected $fillable = [
        'user_id', 'plan_id', 'status', 'starts_at', 'trial_ends_at', 'period_starts_at',
        'ends_at', 'canceled_at', 'gateway', 'gateway_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'period_starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ── Access ───────────────────────────────────────────────────────

    /**
     * Subscriptions that grant access right now — decided by dates, so no cron is needed:
     *  - trialing / active: until ends_at + grace days (or forever when ends_at is empty)
     *  - canceled: until ends_at (access to the end of the paid period, no grace)
     *  - pending / expired: never
     */
    public function scopeActive(Builder $query): Builder
    {
        $grace = Billing::graceDays();

        return $query->where(function (Builder $query) use ($grace): void {
            $query
                ->where(fn (Builder $q) => $q
                    ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
                    ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()->subDays($grace))))
                ->orWhere(fn (Builder $q) => $q
                    ->where('status', SubscriptionStatus::Canceled->value)
                    ->where('ends_at', '>', now()));
        });
    }

    public function isActive(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => $this->ends_at === null || $this->ends_at->gt(now()->subDays(Billing::graceDays())),
            SubscriptionStatus::Canceled => $this->ends_at !== null && $this->ends_at->isFuture(),
            default => false,
        };
    }

    public function inGracePeriod(): bool
    {
        return in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)
            && $this->ends_at?->isPast()
            && $this->isActive();
    }

    /**
     * Status as the user should see it (e.g. "active" whose end date passed → expired).
     */
    public function displayStatus(): SubscriptionStatus
    {
        if ($this->status !== SubscriptionStatus::Pending && $this->status !== SubscriptionStatus::Expired && ! $this->isActive()) {
            return SubscriptionStatus::Expired;
        }

        return $this->status;
    }

    public function hasPendingPayment(): bool
    {
        return $this->payments()->where('status', PaymentStatus::Pending->value)->exists();
    }

    /**
     * Can be renewed: the plan has periods, not waiting for a payment, and it ends within 7 days or already ended.
     */
    public function canRenew(): bool
    {
        return $this->plan?->hasPeriod()
            && $this->status !== SubscriptionStatus::Pending
            && $this->ends_at !== null
            && $this->ends_at->lte(now()->addDays(7))
            && ! $this->hasPendingPayment();
    }

    /**
     * Start of the current billing period (for usage that resets every cycle).
     */
    public function currentPeriodStart(): Carbon
    {
        return $this->period_starts_at ?? $this->starts_at ?? $this->created_at;
    }
}
