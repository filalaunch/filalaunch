<?php

namespace App\Console\Commands;

use App\Support\Billing\Billing;
use Illuminate\Console\Command;

class SyncSubscriptionsCommand extends Command
{
    protected $signature = 'filalaunch:subscriptions:sync';

    protected $description = 'Mark ended subscriptions as expired (labels only — access is always decided by dates)';

    public function handle(Billing $billing): int
    {
        $this->components->info($billing->syncStatuses().' subscription(s) marked expired.');

        return self::SUCCESS;
    }
}
