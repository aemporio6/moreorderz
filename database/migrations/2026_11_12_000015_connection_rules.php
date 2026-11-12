<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Task rule for subscriptions whose due date has passed (screen 12.7). */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('sys_rules')->where('rule_code', 'ACC_SUBSCRIPTION_DUE')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'ACC_SUBSCRIPTION_DUE', 'name' => 'Subscription payment due', 'module' => 'Accounts', 'level' => 'NORMAL', 'assign_to' => 'role:FINANCE', 'due_days' => 0,
                'handler' => 'Subscriptions::nightly', 'f_active' => 1, 'priority' => 'NORMAL', 'category_id' => 12, 'dedupe_key' => 'ACC_SUBSCRIPTION_DUE:<subscription>', 'auto_close' => 'paid or switched off', 'fix_link' => '#/subscriptions']);
    }

    public function down(): void
    {
        DB::table('sys_rules')->where('rule_code', 'ACC_SUBSCRIPTION_DUE')->delete();
    }
};
