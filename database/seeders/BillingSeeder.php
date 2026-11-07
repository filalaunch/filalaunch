<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\LimitBehavior;
use App\Enums\MeterReset;
use App\Enums\PlanType;
use App\Models\Meter;
use App\Models\Permission;
use App\Models\Plan;
use App\Support\Billing\Billing;
use App\Support\Billing\ModuleAccess;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * Sample plans for testing: Free, Pro Monthly, Lifetime, Pay-as-you-go.
 * Safe to re-run (matches by slug). Edit or delete them under Billing → Plans.
 */
class BillingSeeder extends Seeder
{
    public function run(Settings $settings): void
    {
        $currency = Billing::currency();

        $meter = Meter::query()->firstOrCreate(['key' => 'api_calls'], [
            'name' => 'API calls',
            'unit' => 'call',
            'reset' => MeterReset::Monthly,
        ]);

        // Paid plans include every non-kit module permission that exists right now.
        $modulePermissions = Permission::query()->get()
            ->reject(fn (Permission $permission): bool => in_array($permission->getGroup(), ModuleAccess::kitPrefixes(), true));

        $plans = [
            ['slug' => 'free', 'name' => 'Free', 'type' => PlanType::Subscription, 'price' => 0, 'interval' => BillingInterval::Month, 'sort' => 1,
                'description' => 'Get started at no cost.', 'features' => ['Dashboard access', 'Community support'], 'permissions' => false],
            ['slug' => 'pro-monthly', 'name' => 'Pro Monthly', 'type' => PlanType::Subscription, 'price' => 1900, 'interval' => BillingInterval::Month, 'trial_days' => 14, 'sort' => 2, 'is_featured' => true,
                'description' => 'Everything you need, billed monthly.', 'features' => ['All modules', '14-day free trial', 'Email support'], 'permissions' => true],
            ['slug' => 'lifetime', 'name' => 'Lifetime', 'type' => PlanType::OneTime, 'price' => 19900, 'access_days' => null, 'sort' => 3,
                'description' => 'Pay once, use forever.', 'features' => ['All modules', 'Lifetime updates'], 'permissions' => true],
            ['slug' => 'pay-as-you-go', 'name' => 'Pay-as-you-go', 'type' => PlanType::Usage, 'price' => 0, 'interval' => BillingInterval::Month, 'sort' => 4,
                'description' => 'Pay only for what you use.', 'features' => ['All modules', '1,000 API calls included / month', '$0.01 per extra call'], 'permissions' => true,
                'meter' => ['included' => 1000, 'unit_price' => 1, 'on_limit' => LimitBehavior::Overage]],
        ];

        foreach ($plans as $data) {
            $plan = Plan::query()->updateOrCreate(['slug' => $data['slug']], [
                'name' => $data['name'],
                'description' => $data['description'],
                'type' => $data['type'],
                'price' => $data['price'],
                'currency' => $currency,
                'interval' => $data['interval'] ?? null,
                'interval_count' => 1,
                'trial_days' => $data['trial_days'] ?? 0,
                'access_days' => $data['access_days'] ?? null,
                'features' => $data['features'],
                'is_active' => true,
                'is_featured' => $data['is_featured'] ?? false,
                'sort' => $data['sort'],
            ]);

            if ($data['permissions']) {
                $plan->syncPermissions($modulePermissions);
            }

            if (isset($data['meter'])) {
                $plan->planMeters()->updateOrCreate(['meter_id' => $meter->id], $data['meter']);
            }
        }

        // Bank transfer starts disabled with sample details — fill in yours under Billing → Billing settings.
        if ($settings->get('billing.bank_transfer') === null) {
            $settings->setGroup('billing.bank_transfer', [
                'enabled' => false,
                'account_name' => 'Your Company Ltd',
                'account_number' => '000123456789',
                'bank_name' => 'Example Bank',
                'routing_code' => 'EXMP0001234',
                'branch' => '',
                'instructions' => 'Write the payment reference in the transfer description, then upload the receipt.',
            ]);
        }
    }
}
