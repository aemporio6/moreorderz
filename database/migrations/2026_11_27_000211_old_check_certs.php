<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 27-11 01:30: Old tool check (Home › Plan & progress beside it). MR-0139: task PRD_CERT_EXPIRY before a product certificate ends. */
return new class extends Migration
{
    public function up(): void
    {
        $tpl = DB::table('sidebar_modules')->where('module_key', 'M_1_ROADMAP')->first();
        if ($tpl && ! DB::table('sidebar_modules')->where('module_key', 'M_1_OLD_CHECK')->exists()) {
            $row = (array) $tpl; unset($row['id'], $row['id_module']);
            DB::table('sidebar_modules')->insert(array_merge($row, ['module_key' => 'M_1_OLD_CHECK', 'module_name' => 'Old tool check', 'url' => '#/old-tool-check', 'sort_order' => (int) $tpl->sort_order + 1, 'created_at' => now()]));
        }
        if (! DB::table('sys_rules')->where('rule_code', 'PRD_CERT_EXPIRY')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'PRD_CERT_EXPIRY', 'name' => 'Renew a product certificate before it ends', 'module' => 'Products', 'level' => 'NORMAL', 'assign_to' => 'role:MANAGER', 'due_days' => 14, 'handler' => 'ProductCerts::sweep', 'f_active' => 1,
                'priority' => 'NORMAL', 'owner_rule' => 'role:MANAGER', 'dedupe_key' => 'PRD_CERT_EXPIRY:<certificate>', 'auto_close' => 'renewed or switched off', 'fix_link' => '#/list/product_certificate']);
    }

    public function down(): void
    {
        DB::table('sidebar_modules')->where('module_key', 'M_1_OLD_CHECK')->delete();
        DB::table('sys_rules')->where('rule_code', 'PRD_CERT_EXPIRY')->delete();
    }
};
