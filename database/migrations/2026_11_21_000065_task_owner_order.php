<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Task owner order (R-G_CORE-94): old role names on rule rows map to the 9 roles; TASK_URGENT_MONEY already seeded (1,000). */
return new class extends Migration {
    public function up(): void
    {
        foreach (['SOURCING' => 'MANAGER', 'FINANCE_VA' => 'FINANCE', 'OWNER_HR' => 'OWNER', 'LISTING' => 'TL'] as $old => $new)
            DB::table('sys_rules')->where('assign_to', 'like', "%role:$old%")->update(['assign_to' => DB::raw("REPLACE(assign_to, 'role:$old', 'role:$new')")]);
    }
    public function down(): void {}
};
