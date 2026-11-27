<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Found on live 27-11: mo:finance-hourly failed every hour – "Duplicate entry 'company' for key ux_alert_open". The key was unique over every rule,
 * every company and every closed alert, so two rules about the company (or one alert raised again after it was resolved) could not both exist.
 * One open alert per company + rule + subject is kept by the code (it looks for the open one first); the index only speeds that look-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (collect(DB::select("SHOW INDEX FROM fin_alerts WHERE Key_name = 'ux_alert_open'"))->count()) DB::statement('ALTER TABLE fin_alerts DROP INDEX ux_alert_open');
        if (! collect(DB::select("SHOW INDEX FROM fin_alerts WHERE Key_name = 'ix_alert_open'"))->count()) DB::statement('ALTER TABLE fin_alerts ADD INDEX ix_alert_open (company_id, code, ref_key, status)');
    }

    public function down(): void {}
};
