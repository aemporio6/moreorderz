<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-D-45: Inventory › Inventory setup – the four catalogs on one page. */
return new class extends Migration
{
    public function up(): void
    {
        $tpl = DB::table('sidebar_modules')->where('module_key', 'M_INV_SHOP_CHECK')->first();
        if ($tpl && ! DB::table('sidebar_modules')->where('module_key', 'M_INV_SETUP')->exists()) {
            $row = (array) $tpl; unset($row['id'], $row['id_module']);
            DB::table('sidebar_modules')->insert(array_merge($row, ['module_key' => 'M_INV_SETUP', 'module_name' => 'Inventory setup', 'url' => '#/inventory-setup', 'sort_order' => 9, 'created_at' => now()]));
        }
    }

    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_INV_SETUP')->delete(); }
};
