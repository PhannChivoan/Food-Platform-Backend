<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// run with: php artisan schedule:work  (or a cron entry: * * * * * php artisan schedule:run)
\Illuminate\Support\Facades\Schedule::command("orders:expire-unpaid")->everyMinute();
\Illuminate\Support\Facades\Schedule::command("riders:reassign-stale")->everyTwoMinutes();
