<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-35 one log line per task-worker run (sys_job_runs.note); R-G_CORE-25 PERIOD_OPENED is a shop-month (aud_runs): its go-live baseline. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sys_job_runs', 'note')) DB::statement('ALTER TABLE sys_job_runs ADD note VARCHAR(500) NULL');
        DB::table('tm_trigger_seen')->where('event', 'PERIOD_OPENED')->delete();      // the first look was taken on fin_periods; a period is a shop-month close run
        \App\Services\TaskTemplates::baseline();
    }

    public function down(): void
    {
        if (Schema::hasColumn('sys_job_runs', 'note')) DB::statement('ALTER TABLE sys_job_runs DROP COLUMN note');
    }
};
