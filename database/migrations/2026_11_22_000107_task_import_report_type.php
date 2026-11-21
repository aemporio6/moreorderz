<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-34: the task CSV import is report type TASKS of the Import Center. */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('imp_report_types')->where('code', 'TASKS')->exists())
            DB::table('imp_report_types')->insert(['code' => 'TASKS', 'name' => 'Tasks (CSV)', 'module' => 'Tasks', 'function_name' => 'New tasks – check first, all or nothing, undo by batch', 'handler' => 'TaskImport::run',
                'duplicate_key' => 'import_ref', 'match_with' => null, 'period_rule' => 'NONE', 'f_active' => 1]);
    }

    public function down(): void {}
};
