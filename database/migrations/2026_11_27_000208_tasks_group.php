<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 27-11 00:09 / 00:30: Tasks is a big module – its own menu group, with the board, the weekly view and the templates in it. */
return new class extends Migration
{
    public function up(): void
    {
        $sec = DB::table('sidebar_modules')->where('module_key', 'SEC_13')->value('id_module'); if (! $sec) return;
        DB::table('sidebar_modules')->where('module_key', 'SEC_13')->update(['module_name' => 'Tasks']);
        foreach ([['M_13_BOARD', 'Task board (kanban + calendar)', '#/tasks/board', 15], ['M_13_WEEKLY', 'This week', '#/tasks?scope=weekly', 16], ['M_13_TEMPLATES', 'Task templates', '#/task-templates', 17], ['M_13_ABSENCE', 'Who is away (calendar)', '#/tasks/calendar', 18]] as [$k, $n, $u, $o])
            if (DB::table('sidebar_modules')->where('module_key', $k)->doesntExist())
                DB::table('sidebar_modules')->insert(['id_module' => (int) DB::table('sidebar_modules')->max('id_module') + 1, 'module_key' => $k, 'module_name' => $n, 'url' => $u, 'parent_id' => $sec, 'sort_order' => $o, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'all (by rights)', 'forms' => '']);
    }

    public function down(): void {}
};
