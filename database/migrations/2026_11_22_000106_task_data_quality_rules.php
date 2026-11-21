<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-38 (new): data-quality rule tasks for open tasks without a due date / not linked to a record (one a day, closed when none are left). */
return new class extends Migration {
    public function up(): void
    {
        foreach (['TASK_NO_DUE' => 'Open tasks without a due date', 'TASK_NOT_LINKED' => 'Open tasks not linked to a record'] as $code => $name)
            if (! DB::table('sys_rules')->where('rule_code', $code)->exists())
                DB::table('sys_rules')->insert(['rule_code' => $code, 'name' => $name, 'module' => 'Tasks', 'level' => 'NORMAL', 'assign_to' => 'role:MANAGER', 'due_days' => 1, 'handler' => 'TaskLists::dataQuality', 'f_active' => 1,
                    'priority' => 'NORMAL', 'category_id' => null, 'dedupe_key' => $code . ':<company>:<date>', 'auto_close' => 'no such tasks left', 'fix_link' => '#/tasks?scope=all']);
    }

    public function down(): void {}
};
