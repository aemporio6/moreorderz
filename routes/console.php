<?php

use Illuminate\Support\Facades\Schedule;

// The server runs `php artisan schedule:run` every minute (one cron line).
// Live 27-11: a job the server stopped kept its overlap lock for 24 hours (Laravel's default) – each lock now ends after about two of its intervals.
Schedule::command('mo:events')->everyMinute()->withoutOverlapping(10);
Schedule::command('mo:nightly')->dailyAt('02:30')->withoutOverlapping(720);
// temporary file retention at 02:30 company time (R-G_CORE-75; COMPANY_TIMEZONE, default Europe/London)
Schedule::command('mo:cleanup-temp')->dailyAt('02:30')->timezone((function () { try { return (string) (\App\Services\Settings::get('COMPANY_TIMEZONE', 0, 'Europe/London') ?: 'Europe/London'); } catch (\Throwable $e) { return 'Europe/London'; } })())->withoutOverlapping(720);
// messages are sent by the task worker inside mo:events (R-G_CORE-35 dispatch step); mo:send-messages stays for a manual run
Schedule::command('mo:pull')->hourlyAt(17)->withoutOverlapping(120);
Schedule::command('mo:finance-hourly')->hourly()->withoutOverlapping(120);      // R-FIN-32: minute 0, lock + run log (JobLog)
Schedule::command('mo:finance-nightly')->dailyAt('00:30')->withoutOverlapping(720);      // R-FIN-32: stock snapshot (R-FIN-51)
Schedule::command('mo:finance-monthly')->monthlyOn(1, '08:00')->withoutOverlapping(720);      // R-FIN-32 / 50: partner monthly summaries
Schedule::command('mo:src-alerts')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('mo:dispatch-watch')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('mo:backup')->dailyAt('03:15')->withoutOverlapping(720);      // MR-0095 daily backup, 14 daily + 12 monthly kept
Schedule::command('mo:backup --drill')->monthlyOn(2, '04:00')->withoutOverlapping(720);      // MR-0095 monthly restore drill
Schedule::command('mo:fx-live')->cron('20 6,15,17 * * 1-5')->withoutOverlapping(30);      // owner 27-11 09:33: live exchange rates (ECB publishes about 16:00 CET)
Schedule::command('mo:logos')->dailyAt('05:40')->withoutOverlapping(120);      // owner 27-11 09:26: logos – missing ones daily, each refreshed monthly
