<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 26-11 23:23: "Plan & progress" – the one list of everything – first under Home. */
return new class extends Migration
{
    public function up(): void
    {
        $sec = DB::table('sidebar_modules')->where('module_key', 'M_1_1_OWNER_HOME_COMMAND_CENTER_')->value('parent_id');
        if ($sec && DB::table('sidebar_modules')->where('module_key', 'M_1_ROADMAP')->doesntExist())
            DB::table('sidebar_modules')->insert(['id_module' => (int) DB::table('sidebar_modules')->max('id_module') + 1, 'module_key' => 'M_1_ROADMAP', 'module_name' => 'Plan & progress', 'url' => '#/roadmap', 'parent_id' => $sec,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $sec)->max('sort_order') + 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER,PARTNER,MANAGER', 'forms' => '']);
    }

    public function down(): void {}
};
