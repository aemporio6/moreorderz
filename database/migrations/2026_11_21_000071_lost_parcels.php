<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Lost parcels and carrier claims (R-H-36): menu Dispatch › Lost parcels & claims. */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('module_key', 'M_7_8_LOST_PARCELS')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_7_8_LOST_PARCELS', 'module_name' => 'Lost parcels & claims', 'url' => '#/lost-parcels', 'parent_id' => 90700, 'sort_order' => 8, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'WAREHOUSE, STOCK, MANAGER, FINANCE, OWNER', 'screen_no' => '7.8']);
    }
    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_7_8_LOST_PARCELS')->delete(); }
};
