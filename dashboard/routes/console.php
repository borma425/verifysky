<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:sync-edge-usage')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('billing:reconcile-expired-subscriptions')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('billing:reconcile-expired-plan-grants')
    ->hourly()
    ->withoutOverlapping();
