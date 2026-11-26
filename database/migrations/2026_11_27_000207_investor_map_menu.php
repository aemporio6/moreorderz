<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 27-11 00:30: the investor mapping (old investor number → its company) is reachable from the Partners group. */
return new class extends Migration
{
    public function up(): void
    {
        $sec = DB::table('sidebar_modules')->where('screen_no', '11.1')->value('parent_id');
        if ($sec && DB::table('sidebar_modules')->where('module_key', 'M_11_INVESTOR_MAP')->doesntExist())
            DB::table('sidebar_modules')->insert(['id_module' => (int) DB::table('sidebar_modules')->max('id_module') + 1, 'module_key' => 'M_11_INVESTOR_MAP', 'module_name' => 'Investor links (old numbers)', 'url' => '#/investor-map', 'parent_id' => $sec,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $sec)->max('sort_order') + 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER', 'forms' => '']);
    }

    public function down(): void {}
};
