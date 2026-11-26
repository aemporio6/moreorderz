<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** MR-1443 … 1450: "What to do next" (decision engine) in the Reports section. */
return new class extends Migration
{
    public function up(): void
    {
        $sec = DB::table('sidebar_modules')->where('module_key', 'SEC_16')->value('id_module');
        if ($sec && DB::table('sidebar_modules')->where('module_key', 'M_16_DECISIONS')->doesntExist())
            DB::table('sidebar_modules')->insert(['id_module' => (int) DB::table('sidebar_modules')->max('id_module') + 1, 'module_key' => 'M_16_DECISIONS', 'module_name' => 'What to do next', 'url' => '#/decisions', 'parent_id' => $sec,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $sec)->min('sort_order') - 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER,MANAGER,FINANCE,PARTNER', 'forms' => '']);
    }

    public function down(): void {}
};
