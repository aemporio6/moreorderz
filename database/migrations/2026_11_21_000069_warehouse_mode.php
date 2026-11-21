<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Simple / full warehouse by settings (R-H-7): menu rows "Warehouse mode" and "Putaway"; the RECEIVING system location name. */
return new class extends Migration {
    public function up(): void
    {
        foreach ([['M_4_16_WAREHOUSE_MODE', 'Warehouse mode (Simple / Full)', '#/warehouse-mode', 16, 'OWNER, MANAGER', '4.16'], ['M_4_17_PUTAWAY', 'Putaway', '#/putaway', 17, 'WAREHOUSE, STOCK, MANAGER', '4.17']] as [$k, $n, $u, $o, $r, $no])
            if (! DB::table('sidebar_modules')->where('module_key', $k)->exists()) DB::table('sidebar_modules')->insert(['module_key' => $k, 'module_name' => $n, 'url' => $u, 'parent_id' => 90400, 'sort_order' => $o, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => $r, 'screen_no' => $no]);
    }
    public function down(): void { DB::table('sidebar_modules')->whereIn('module_key', ['M_4_16_WAREHOUSE_MODE', 'M_4_17_PUTAWAY'])->delete(); }
};
