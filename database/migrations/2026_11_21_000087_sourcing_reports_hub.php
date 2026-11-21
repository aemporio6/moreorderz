<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-F_SETUP-1: menu row "Sourcing reports" (hub with Per cycle / Global / Per PO). */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('module_key', 'M_3_19_SOURCING_REPORTS')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_3_19_SOURCING_REPORTS', 'module_name' => 'Sourcing reports', 'url' => '#/sourcing-reports', 'parent_id' => 90300, 'sort_order' => 19, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER, MANAGER, SOURCING, PARTNER, FINANCE', 'screen_no' => '3.19']);
    }
    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_3_19_SOURCING_REPORTS')->delete(); }
};
