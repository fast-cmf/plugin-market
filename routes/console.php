<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// License heartbeat (design §4.5): daily, silent, fail-open.
Schedule::command('market:heartbeat')->dailyAt('03:17')->withoutOverlapping();
