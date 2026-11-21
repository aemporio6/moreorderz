<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Dispatch deadline (R-H-24): menu Setup › Non-working days (bank holidays per country) – the calendar the deadline counts in. */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('module_key', 'M_17_21_NON_WORKING_DAYS')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_17_21_NON_WORKING_DAYS', 'module_name' => 'Non-working days (bank holidays)', 'url' => '#/list/non_working_day', 'parent_id' => 91700, 'sort_order' => 21, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER, MANAGER, ADMIN', 'screen_no' => '17.21', 'forms' => 'non_working_day(C)']);
    }
    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_17_21_NON_WORKING_DAYS')->delete(); }
};
