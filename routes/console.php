<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('shorts:auto-generate')->hourly()->withoutOverlapping();
Schedule::command('shorts:auto-schedule')->hourly()->withoutOverlapping();
Schedule::command('shorts:publish-due')->everyMinute()->withoutOverlapping();
Schedule::command('shorts:cleanup-published')->hourly()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
