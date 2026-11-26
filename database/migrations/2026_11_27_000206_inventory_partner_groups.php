<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 27-11 00:09: Inventory and Partners are big modules – each gets its own button in the menu, with its screens inside. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('sidebar_modules')->where('module_key', 'SEC_STOCKINV')->doesntExist())
            DB::table('sidebar_modules')->insert(['id_module' => 90350, 'module_key' => 'SEC_STOCKINV', 'module_name' => 'Inventory', 'url' => null, 'parent_id' => null, 'sort_order' => 35, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => null, 'forms' => '']);
        $sec = (int) DB::table('sidebar_modules')->where('module_key', 'SEC_STOCKINV')->value('id_module');
        DB::table('sidebar_modules')->where('module_key', 'M_5_INVENTORY')->update(['parent_id' => $sec, 'sort_order' => 1]);
        foreach ([['M_INV_FBA_CHECK', 'FBA check', '#/fba-check', 2], ['M_INV_EACH_PARTNER_STOCK', 'Stock by partner', '#/partner-view', 3]] as [$k, $n, $u, $o])
            if (DB::table('sidebar_modules')->where('module_key', $k)->doesntExist())
                DB::table('sidebar_modules')->insert(['id_module' => (int) DB::table('sidebar_modules')->max('id_module') + 1, 'module_key' => $k, 'module_name' => $n, 'url' => $u, 'parent_id' => $sec, 'sort_order' => $o, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'all (by rights)', 'forms' => '']);
    }

    public function down(): void {}
};
