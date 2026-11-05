<?php

use App\Support\Mail\QueuedEmail;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled jobs
|--------------------------------------------------------------------------
| Production needs ONE cron entry (cPanel or crontab):
|   * * * * * cd /path/to/portal && php artisan schedule:run >> /dev/null 2>&1
| All times are Asia/Colombo.
*/

$tz = 'Asia/Colombo';

// Shared hosting has no long-running worker: drain the queue every minute.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()->withoutOverlapping()->runInBackground();

// Lets Admin → Email queue show whether cron is running.
Schedule::call([QueuedEmail::class, 'beat'])->name('portal-heartbeat')->everyMinute();

// Police clearance renewal reminders (30/14/7 days, configurable) + expiry notices.
Schedule::command('pcc:send-reminders')->dailyAt('06:00')->timezone($tz)->withoutOverlapping()->onOneServer();

// Weekly Operations digest of assignments at risk from expiring clearance.
Schedule::command('pcc:ops-expiry-digest')->weeklyOn(1, '07:00')->timezone($tz)->onOneServer();

// Housekeeping.
Schedule::command('vacancies:close-expired')->dailyAt('00:15')->timezone($tz);
Schedule::command('quotations:expire')->dailyAt('00:20')->timezone($tz);
Schedule::command('auth:clear-resets')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
