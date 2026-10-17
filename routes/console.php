<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('posts:publish-due')->everyMinute()->withoutOverlapping();
// Comments are only re-downloaded when something changed, so frequent syncs stay cheap on API quota.
Schedule::command('social:sync')->cron('*/'.config('app.sync_every_minutes').' * * * *')->withoutOverlapping();
Schedule::command('billing:maintain')->hourly()->withoutOverlapping();
Schedule::command('media:cleanup')->daily()->withoutOverlapping();
