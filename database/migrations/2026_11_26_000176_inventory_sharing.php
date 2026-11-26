<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-D-94 Stock › Inventory sharing: menu row + search words (R-G_CORE-100). */
return new class extends Migration {
    public function up(): void
    {
        $parent = DB::table('sidebar_modules')->where('module_key', 'M_5_8_PRODUCTS_NOT_LISTED')->value('parent_id');
        if ($parent && ! DB::table('sidebar_modules')->where('module_key', 'M_5_INVENTORY_SHARING')->exists()) {
            $id = (int) DB::table('sidebar_modules')->where('parent_id', $parent)->max('id_module') + 1; while (DB::table('sidebar_modules')->where('id_module', $id)->exists()) $id++;
            DB::table('sidebar_modules')->insert(['id_module' => $id, 'module_key' => 'M_5_INVENTORY_SHARING', 'module_name' => 'Inventory sharing', 'url' => '#/inventory-sharing', 'parent_id' => $parent,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $parent)->max('sort_order') + 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'TL, MANAGER, WAREHOUSE, OWNER', 'forms' => 't_product_listings(Q)']);
        }
        /* R-G_CORE-82 data-quality rules */
        foreach (['DQ_ITEM_NOTE' => ['Sale line with an item note and no vendor', 'vendor set or bought locally', '#/data-quality'], 'DQ_PARENT_INACTIVE' => ['Active child of an inactive parent', 'parent active again or child inactive', '#/data-quality']] as $code => [$name, $close, $link])
            if (! DB::table('sys_rules')->where('rule_code', $code)->exists())
                DB::table('sys_rules')->insert(['rule_code' => $code, 'name' => $name, 'module' => 'Products', 'level' => 'NORMAL', 'assign_to' => 'role:MANAGER', 'due_days' => 3, 'handler' => 'DataQuality::run', 'f_active' => 1,
                    'priority' => 'NORMAL', 'owner_rule' => 'role:MANAGER', 'dedupe_key' => $code . ':<record>', 'auto_close' => $close, 'fix_link' => $link]);
        \App\Services\SearchVocab::load();
    }

    public function down(): void {}
};
