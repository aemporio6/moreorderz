<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-D-66 screen "Old carrier values to decide": menu row (beside Carriers) + search words. */
return new class extends Migration {
    public function up(): void
    {
        $parent = DB::table('sidebar_modules')->where('screen_no', '17.4')->value('parent_id') ?: DB::table('sidebar_modules')->where('module_key', 'M_5_INVENTORY_SHARING')->value('parent_id');
        if ($parent && ! DB::table('sidebar_modules')->where('module_key', 'M_17_CARRIER_VALUES')->exists()) {
            $id = (int) DB::table('sidebar_modules')->where('parent_id', $parent)->max('id_module') + 1; while (DB::table('sidebar_modules')->where('id_module', $id)->exists()) $id++;
            DB::table('sidebar_modules')->insert(['id_module' => $id, 'module_key' => 'M_17_CARRIER_VALUES', 'module_name' => 'Old carrier values to decide', 'url' => '#/carrier-values', 'parent_id' => $parent,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $parent)->max('sort_order') + 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER', 'forms' => '']);
        }
        \App\Services\SearchVocab::load();
    }

    public function down(): void {}
};
