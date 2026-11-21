<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Settings › Warehouse (R-H-57): menu row, and a default row for every switch of the page that has none yet (loader rule L39). */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('module_key', 'M_17_31_WAREHOUSE_SETTINGS')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_17_31_WAREHOUSE_SETTINGS', 'module_name' => 'Warehouse settings', 'url' => '#/settings/warehouse', 'parent_id' => 91700, 'sort_order' => 31, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER', 'screen_no' => '17.31']);
        foreach (\App\Services\WarehouseSettings::defs() as $k => $d)
            if (! DB::table('sys_settings')->where('setting_key', $k)->where('fk_id_ent', 0)->exists()) DB::table('sys_settings')->insert(['fk_id_ent' => 0, 'setting_key' => $k, 'setting_value' => $d[2] === '' ? null : $d[2], 'updated_at' => now()]);
    }
    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_17_31_WAREHOUSE_SETTINGS')->delete(); }
};
