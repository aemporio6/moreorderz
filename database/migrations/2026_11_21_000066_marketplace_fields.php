<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Marketplace field builder (R-A_SALES-67): menu Products & Listings › Marketplace fields; old 0/'' options → NULL. */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('module_key', 'M_5_10_MARKETPLACE_FIELDS')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_5_10_MARKETPLACE_FIELDS', 'module_name' => 'Marketplace fields', 'url' => '#/mp-fields', 'parent_id' => 90500, 'sort_order' => 10, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER, MANAGER, TL, VA', 'screen_no' => '5.10', 'forms' => 'marketplace_field(C)']);
        DB::table('cat_marketplace_fields')->where('field_type', '<>', 'select')->update(['field_options' => null]);
    }
    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_5_10_MARKETPLACE_FIELDS')->delete(); }
};
