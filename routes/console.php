<?php

use Illuminate\Support\Facades\Schedule;

// The server runs `php artisan schedule:run` every minute (one cron line).
Schedule::command('mo:events')->everyMinute()->withoutOverlapping();
Schedule::command('mo:nightly')->dailyAt('02:30')->withoutOverlapping();
// temporary file retention at 02:30 company time (R-G_CORE-75; COMPANY_TIMEZONE, default Europe/London)
Schedule::command('mo:cleanup-temp')->dailyAt('02:30')->timezone((function () { try { return (string) (\App\Services\Settings::get('COMPANY_TIMEZONE', 0, 'Europe/London') ?: 'Europe/London'); } catch (\Throwable $e) { return 'Europe/London'; } })())->withoutOverlapping();
Schedule::command('mo:send-messages')->everyMinute()->withoutOverlapping();
Schedule::command('mo:pull')->hourlyAt(17)->withoutOverlapping();
Schedule::command('mo:src-alerts')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('mo:dispatch-watch')->everyFifteenMinutes()->withoutOverlapping();
