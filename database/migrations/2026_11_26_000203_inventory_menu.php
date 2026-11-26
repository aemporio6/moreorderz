<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 26-11 23:14: "Inventory" first in Products & Listings (and reachable from Receiving & Warehouse). */
return new class extends Migration
{
    public function up(): void
    {
        $sec = DB::table('sidebar_modules')->where('module_key', 'SEC_5')->value('id_module');
        if ($sec && DB::table('sidebar_modules')->where('module_key', 'M_5_INVENTORY')->doesntExist())
            DB::table('sidebar_modules')->insert(['id_module' => (int) DB::table('sidebar_modules')->max('id_module') + 1, 'module_key' => 'M_5_INVENTORY', 'module_name' => 'Inventory', 'url' => '#/inventory', 'parent_id' => $sec,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $sec)->min('sort_order') - 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'all (by rights)', 'forms' => '']);
    }

    public function down(): void {}
};
