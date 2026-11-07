<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Optional: tidies subscription status labels. Access never depends on it (it's decided by dates).
Schedule::command('filalaunch:subscriptions:sync')->daily();
