<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 26-11 21:31 (from MY TOOL supplier_link): "From suppliers" – files suppliers send with their upload link. */
return new class extends Migration
{
    public function up(): void
    {
        $sec = DB::table('sidebar_modules')->where('module_key', 'SEC_14')->value('id_module');
        if ($sec && DB::table('sidebar_modules')->where('module_key', 'M_14_SUPPLIER_INBOX')->doesntExist()) {
            $id = (int) DB::table('sidebar_modules')->max('id_module') + 1;
            DB::table('sidebar_modules')->insert(['id_module' => $id, 'module_key' => 'M_14_SUPPLIER_INBOX', 'module_name' => 'From suppliers', 'url' => '#/supplier-inbox', 'parent_id' => $sec,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $sec)->max('sort_order') + 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER,MANAGER,SOURCING,FINANCE', 'forms' => '']);
        }
    }

    public function down(): void {}
};
