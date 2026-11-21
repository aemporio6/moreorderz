<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-FIN-50: task rule FIN_REPORT_MISSING (closes itself when the next settlement arrives) and the WhatsApp template MONTHLY_SUMMARY. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('sys_rules')->where('rule_code', 'FIN_REPORT_MISSING')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'FIN_REPORT_MISSING', 'name' => 'Settlement report missing for a shop', 'module' => 'Finance', 'level' => 'NORMAL', 'assign_to' => 'shop.va,role:FINANCE', 'due_days' => 1,
                'handler' => 'FinanceMonthly::missingTasks', 'f_active' => 1, 'priority' => 'HIGH', 'category_id' => 10, 'dedupe_key' => 'FIN_REPORT_MISSING:<shop>', 'auto_close' => 'next settlement arrives', 'fix_link' => '#/settlements']);
        if (! DB::table('wa_templates')->where('code', 'MONTHLY_SUMMARY')->exists())
            DB::table('wa_templates')->insert(['code' => 'MONTHLY_SUMMARY', 'title' => 'Partner monthly summary', 'audience' => 'PARTNER', 'body' => 'Monthly summary: balance {balance}, profit to date {profit}. Open your Partner Account for details. {link}', 'f_active' => 1]);
    }

    public function down(): void
    {
        DB::table('sys_rules')->where('rule_code', 'FIN_REPORT_MISSING')->delete();
        DB::table('wa_templates')->where('code', 'MONTHLY_SUMMARY')->delete();
    }
};
