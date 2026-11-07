<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\PlanType;
use App\Support\Billing\Billing;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'type', 'price', 'currency', 'interval', 'interval_count',
        'trial_days', 'access_days', 'features', 'is_active', 'is_featured', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => PlanType::class,
            'interval' => BillingInterval::class,
            'price' => 'integer',
            'interval_count' => 'integer',
            'trial_days' => 'integer',
            'access_days' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort' => 'integer',
        ];
    }

    // ── Relations ────────────────────────────────────────────────────

    /**
     * Permissions this plan grants while a subscription to it is active.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'plan_permission');
    }

    /**
     * Usage meters (included quantity, unit price, behaviour at limit).
     */
    public function planMeters(): HasMany
    {
        return $this->hasMany(PlanMeter::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Same signature as Spatie's HasPermissions::syncPermissions(), so the shared
     * permission checkboxes (SyncsPermissionCheckboxes) work on plans too.
     *
     * @param  Collection<int, Permission>|array<int, Permission>  $permissions
     */
    public function syncPermissions(Collection|array $permissions): static
    {
        $this->permissions()->sync(collect($permissions)->pluck('id')->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this;
    }

    // ── Scopes ───────────────────────────────────────────────────────

    /**
     * Active plans of the plan types enabled in Billing settings.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereIn('type', app(Billing::class)->enabledTypes())
            ->orderBy('sort')
            ->orderBy('price');
    }

    // ── Pricing helpers ──────────────────────────────────────────────

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    /**
     * Whether a subscription to this plan has an end date (and can be renewed).
     */
    public function hasPeriod(): bool
    {
        return match ($this->type) {
            PlanType::OneTime => $this->access_days !== null,
            PlanType::Subscription => true,
            PlanType::Usage => ! $this->isFree() && $this->interval !== null,
        };
    }

    /**
     * End of an access period starting at $from (null = no end).
     */
    public function periodEnd(Carbon $from): ?Carbon
    {
        if (! $this->hasPeriod()) {
            return null;
        }

        if ($this->type === PlanType::OneTime) {
            return $from->copy()->addDays($this->access_days);
        }

        $count = max(1, $this->interval_count);

        return match ($this->interval ?? BillingInterval::Month) {
            BillingInterval::Day => $from->copy()->addDays($count),
            BillingInterval::Week => $from->copy()->addWeeks($count),
            BillingInterval::Month => $from->copy()->addMonthsNoOverflow($count),
            BillingInterval::Year => $from->copy()->addYearsNoOverflow($count),
        };
    }

    public function intervalLabel(): ?string
    {
        if ($this->interval === null) {
            return null;
        }

        $unit = $this->interval->value;

        return $this->interval_count > 1 ? "every {$this->interval_count} {$unit}s" : $unit;
    }

    /**
     * e.g. "$19.00 / month", "$199.00 one-time", "$0.00 + usage", "Free".
     */
    public function priceLabel(): string
    {
        $price = Money::format($this->price, $this->currency);

        return match ($this->type) {
            PlanType::OneTime => $this->isFree() ? 'Free' : $price.($this->access_days ? " for {$this->access_days} days" : ' one-time'),
            PlanType::Subscription => $this->isFree() ? 'Free' : $price.' / '.$this->intervalLabel(),
            PlanType::Usage => ($this->isFree() ? 'Pay as you go' : $price.' / '.$this->intervalLabel().' + usage'),
        };
    }
}
