<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Stock alerts page (R-D-46): menu row, the adjustment reasons list (R-H-48 names) when it is empty, STK_LOW fix link. */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('module_key', 'M_4_19_STOCK_ALERTS')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_4_19_STOCK_ALERTS', 'module_name' => 'Stock alerts', 'url' => '#/stock-alerts', 'parent_id' => 90400, 'sort_order' => 19, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'WAREHOUSE, STOCK, MANAGER, SOURCING', 'screen_no' => '4.19']);
        if (! DB::table('cat_adjustment_reasons')->exists())
            foreach (['Count', 'Damage', 'Loss', 'Found', 'Sample', 'Packing use', 'Supplier return', 'Write off missing', 'Write off damaged'] as $n) DB::table('cat_adjustment_reasons')->insert(['reason_name' => $n, 'f_active' => 1, 'created_at' => now()]);
        DB::table('sys_rules')->where('rule_code', 'STK_LOW')->update(['fix_link' => '#/stock-alerts']);
    }
    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_4_19_STOCK_ALERTS')->delete(); }
};
