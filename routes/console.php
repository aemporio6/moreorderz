<?php

use Illuminate\Support\Facades\Schedule;

// The server runs `php artisan schedule:run` every minute (one cron line).
Schedule::command('mo:events')->everyMinute()->withoutOverlapping();
Schedule::command('mo:nightly')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('mo:send-messages')->everyMinute()->withoutOverlapping();
Schedule::command('mo:pull')->hourlyAt(17)->withoutOverlapping();
