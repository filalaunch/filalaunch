<?php

namespace App\Models\Concerns;

use App\Enums\LimitBehavior;
use App\Enums\MeterReset;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanMeter;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Support\Billing\Billing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Plans, plan-based permissions and usage meters for a user.
 *
 *   $user->planAllows('posts.create');
 *   $user->recordUsage('api_calls');        // false when blocked at the limit
 *   $user->usageRemaining('api_calls');     // null = unlimited
 *   $user->canUse('api_calls', 5);
 */
trait HasSubscriptions
{
    /** @var array<string, list<string>> plan permission names per billing owner */
    protected array $planPermissionCache = [];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Subscriptions that count for this user right now: the current team's (team billing)
     * or the user's own.
     */
    public function billableSubscriptions(): Builder
    {
        $team = Billing::ownerTeam();

        return $team
            ? Subscription::query()->where('team_id', $team->getKey())
            : Subscription::query()->where('user_id', $this->getKey())->whereNull('team_id');
    }

    public function billablePayments(): Builder
    {
        $team = Billing::ownerTeam();

        return $team
            ? Payment::query()->where('team_id', $team->getKey())
            : Payment::query()->where('user_id', $this->getKey())->whereNull('team_id');
    }

    public function activeSubscriptions(): Builder
    {
        return $this->billableSubscriptions()->active();
    }

    /**
     * @return Collection<int, Plan>
     */
    public function activePlans(): Collection
    {
        return Plan::query()->whereIn('id', $this->activeSubscriptions()->select('plan_id'))->get();
    }

    public function hasActivePlan(?string $slug = null): bool
    {
        return $this->activeSubscriptions()
            ->when($slug, fn ($query) => $query->whereHas('plan', fn ($q) => $q->where('slug', $slug)))
            ->exists();
    }

    /**
     * Permission names granted by the user's active plans (cached for the request).
     *
     * @return list<string>
     */
    public function planPermissionNames(): array
    {
        $owner = Billing::ownerTeam()?->getKey() ? 'team-'.Billing::ownerTeam()->getKey() : 'user';

        return $this->planPermissionCache[$owner] ??= rescue(fn () => DB::table('plan_permission')
            ->join('permissions', 'permissions.id', '=', 'plan_permission.permission_id')
            ->whereIn('plan_permission.plan_id', $this->activeSubscriptions()->select('plan_id'))
            ->distinct()
            ->pluck('permissions.name')
            ->all(), [], report: false);
    }

    public function planAllows(string $permission): bool
    {
        return in_array($permission, $this->planPermissionNames(), true);
    }

    public function flushPlanCache(): void
    {
        $this->planPermissionCache = [];
    }

    // ── Usage meters ─────────────────────────────────────────────────

    public function canUse(string $meterKey, int $quantity = 1): bool
    {
        $usage = $this->usage($meterKey);

        if ($usage === null) {
            return false; // no active plan includes this meter
        }

        if ($usage['limit'] === null || $usage['limit'] >= $usage['used'] + $quantity) {
            return true;
        }

        return $usage['overage'];
    }

    /**
     * Record usage. Returns false (and records nothing) when the limit blocks it.
     */
    public function recordUsage(string $meterKey, int $quantity = 1): bool
    {
        if (! $this->canUse($meterKey, $quantity)) {
            return false;
        }

        $usage = $this->usage($meterKey);

        UsageRecord::query()->create([
            'user_id' => $this->id,
            'team_id' => Billing::ownerTeam()?->getKey(),
            'meter_id' => $usage['meter']->id,
            'subscription_id' => $usage['subscription_id'],
            'quantity' => $quantity,
            'recorded_at' => now(),
        ]);

        return true;
    }

    public function usageUsed(string $meterKey): int
    {
        return $this->usage($meterKey)['used'] ?? 0;
    }

    /**
     * Included quantity across active plans (null = unlimited, 0 = no plan includes it).
     */
    public function usageLimit(string $meterKey): ?int
    {
        $usage = $this->usage($meterKey);

        return $usage === null ? 0 : $usage['limit'];
    }

    public function usageRemaining(string $meterKey): ?int
    {
        $usage = $this->usage($meterKey);

        if ($usage === null) {
            return 0;
        }

        return $usage['limit'] === null ? null : max(0, $usage['limit'] - $usage['used']);
    }

    /**
     * Usage of every meter on the user's active plans (for the My plan page).
     *
     * @return list<array{meter: Meter, used: int, limit: ?int, overage: bool, unit_price: int, since: ?Carbon}>
     */
    public function usageSummary(): array
    {
        $keys = Meter::query()
            ->whereIn('id', PlanMeter::query()->whereIn('plan_id', $this->activeSubscriptions()->select('plan_id'))->select('meter_id'))
            ->pluck('key');

        return $keys->map(fn (string $key): ?array => $this->usage($key))->filter()->values()->all();
    }

    /**
     * @return array{meter: Meter, used: int, limit: ?int, overage: bool, unit_price: int, since: ?Carbon, subscription_id: ?int}|null
     */
    protected function usage(string $meterKey): ?array
    {
        $meter = Meter::query()->where('key', $meterKey)->first();

        if ($meter === null) {
            return null;
        }

        $subscriptions = $this->activeSubscriptions()->with('plan')->get()->keyBy('plan_id');
        $planMeters = PlanMeter::query()->where('meter_id', $meter->id)->whereIn('plan_id', $subscriptions->keys())->get();

        if ($planMeters->isEmpty()) {
            return null;
        }

        $subscription = $subscriptions[$planMeters->first()->plan_id];

        $since = match ($meter->reset) {
            MeterReset::Monthly => now()->startOfMonth(),
            MeterReset::Cycle => $subscription->currentPeriodStart(),
            MeterReset::Never => null,
        };

        $team = Billing::ownerTeam();

        $used = (int) UsageRecord::query()
            ->when($team, fn ($q) => $q->where('team_id', $team->getKey()), fn ($q) => $q->where('user_id', $this->id)->whereNull('team_id'))
            ->where('meter_id', $meter->id)
            ->when($since, fn ($query) => $query->where('recorded_at', '>=', $since))
            ->sum('quantity');

        return [
            'meter' => $meter,
            'used' => $used,
            'limit' => $planMeters->contains(fn (PlanMeter $pm): bool => $pm->included === null) ? null : (int) $planMeters->sum('included'),
            'overage' => $planMeters->contains(fn (PlanMeter $pm): bool => $pm->on_limit === LimitBehavior::Overage),
            'unit_price' => (int) $planMeters->min('unit_price'),
            'since' => $since,
            'subscription_id' => $subscription->id,
        ];
    }
}
