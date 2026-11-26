<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 26-11 23:19: "Each partner" first in Partners & Investors. */
return new class extends Migration
{
    public function up(): void
    {
        $sec = DB::table('sidebar_modules')->where('screen_no', '11.1')->value('parent_id');
        if ($sec && DB::table('sidebar_modules')->where('module_key', 'M_11_EACH_PARTNER')->doesntExist())
            DB::table('sidebar_modules')->insert(['id_module' => (int) DB::table('sidebar_modules')->max('id_module') + 1, 'module_key' => 'M_11_EACH_PARTNER', 'module_name' => 'Each partner', 'url' => '#/partner-view', 'parent_id' => $sec,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $sec)->min('sort_order') - 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER,PARTNER,MANAGER,FINANCE', 'forms' => '']);
    }

    public function down(): void {}
};
