<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Gen-1 sale ↔ buy compare (R-A_SALES-57): menu row (read-only legacy view). */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('module_key', 'M_6_10_GEN1_SALE_BUY')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_6_10_GEN1_SALE_BUY', 'module_name' => 'Gen-1 sale ↔ buy (legacy)', 'url' => '#/gen1', 'parent_id' => 90600, 'sort_order' => 10, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER, MANAGER, TL', 'screen_no' => '6.10']);
    }
    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_6_10_GEN1_SALE_BUY')->delete(); }
};
