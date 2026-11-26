<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Menu rows for the screens added in 7e-202 … 7e-204: Data quality, Allocation plan (beside Inventory sharing) and Task board (beside Due dates). */
return new class extends Migration {
    public function up(): void
    {
        $add = function (string $key, string $name, string $url, ?int $parent, string $roles) {
            if (! $parent || DB::table('sidebar_modules')->where('module_key', $key)->exists()) return;
            $id = (int) DB::table('sidebar_modules')->where('parent_id', $parent)->max('id_module') + 1; while (DB::table('sidebar_modules')->where('id_module', $id)->exists()) $id++;
            DB::table('sidebar_modules')->insert(['id_module' => $id, 'module_key' => $key, 'module_name' => $name, 'url' => $url, 'parent_id' => $parent, 'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $parent)->max('sort_order') + 1,
                'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => $roles, 'forms' => '']);
        };
        $stock = DB::table('sidebar_modules')->where('module_key', 'M_5_INVENTORY_SHARING')->value('parent_id'); $tasks = DB::table('sidebar_modules')->where('module_key', 'M_12_8_DUE_DATES')->value('parent_id');
        $add('M_5_DATA_QUALITY', 'Data quality', '#/data-quality', $stock ? (int) $stock : null, 'TL, MANAGER, WAREHOUSE, OWNER');
        $add('M_5_ALLOCATION_PLAN', 'Allocation plan', '#/allocation-plan', $stock ? (int) $stock : null, 'MANAGER, WAREHOUSE, OWNER');
        $add('M_12_TASK_BOARD', 'Task board', '#/tasks/board', $tasks ? (int) $tasks : null, 'all');
    }

    public function down(): void {}
};
