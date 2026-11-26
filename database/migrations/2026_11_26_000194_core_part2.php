<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 7e-222 – Core / Platform part 2:
 * - MR-1542 feature flags per company + release notes seen per person;
 * - MR-1544 rule RET_BUYER_ABUSE, MR-1536 rule FEE_OVERCHARGE;
 * - menu rows: Deadline calendar (MR-1545), Repeated returns, Fee check, What's new.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sys_feature_flags')) Schema::create('sys_feature_flags', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent'); $t->string('code', 40); $t->boolean('f_on')->default(true); $t->integer('updated_by')->nullable(); $t->dateTime('updated_at')->nullable(); $t->unique(['fk_id_ent', 'code']);
        });
        if (! Schema::hasColumn('t_enterprise', 'f_sandbox')) Schema::table('t_enterprise', fn (Blueprint $t) => $t->tinyInteger('f_sandbox')->default(0));      // MR-1541
        if (! Schema::hasTable('sys_release_seen')) Schema::create('sys_release_seen', function (Blueprint $t) { $t->integer('user_id')->primary(); $t->string('version', 20); $t->dateTime('seen_at')->nullable(); });

        $rule = function (string $code, string $name, string $module, string $prio, int $cat, string $owner, string $key, string $close, string $fix) {
            if (DB::table('sys_rules')->where('rule_code', $code)->exists()) return;
            DB::table('sys_rules')->insert(['rule_code' => $code, 'name' => $name, 'module' => $module, 'level' => 'NORMAL', 'assign_to' => 'role:' . explode(' ', $owner)[0], 'due_days' => 3, 'f_active' => 1, 'handler' => 'App\\Services\\' . ($code === 'FEE_OVERCHARGE' ? 'FeeCheck::tasks' : 'ReturnAbuse::tasks'),
                'priority' => $prio, 'category_id' => $cat, 'owner_rule' => $owner, 'dedupe_key' => $key, 'auto_close' => $close, 'fix_link' => $fix]);
        };
        $rule('RET_BUYER_ABUSE', 'Buyer or address with repeated returns / claims', 'Sales', 'NORMAL', 2, 'MANAGER fewest open', 'RET_BUYER_ABUSE:<buyer>', 'below the limit in the window', '#/return-abuse');
        $rule('FEE_OVERCHARGE', 'Marketplace charged more fees than expected', 'Finance', 'NORMAL', 4, 'FINANCE fewest open', 'FEE_OVERCHARGE:<shop>:<month>', 'no overcharge left in the month', '#/fee-check');

        $add = function (string $key, string $name, string $url, ?int $parent, string $roles) {
            if (! $parent || DB::table('sidebar_modules')->where('module_key', $key)->exists()) return;
            $id = (int) DB::table('sidebar_modules')->where('parent_id', $parent)->max('id_module') + 1; while (DB::table('sidebar_modules')->where('id_module', $id)->exists()) $id++;
            DB::table('sidebar_modules')->insert(['id_module' => $id, 'module_key' => $key, 'module_name' => $name, 'url' => $url, 'parent_id' => $parent, 'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $parent)->max('sort_order') + 1,
                'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => $roles, 'forms' => '']);
        };
        $p = fn (string $k) => ($x = DB::table('sidebar_modules')->where('module_key', $k)->value('parent_id')) ? (int) $x : null;
        $add('M_12_DEADLINES', 'Deadline calendar', '#/deadlines', $p('M_12_8_DUE_DATES'), 'all');
        $add('M_6_RETURN_ABUSE', 'Repeated returns', '#/return-abuse', $p('M_6_6_REFUNDS_REFUND_PROPOSALS'), 'OWNER, MANAGER, TL');
        $add('M_9_FEE_CHECK', 'Marketplace fee check', '#/fee-check', $p('M_9_3_PAYOUTS_SETTLEMENTS'), 'OWNER, MANAGER, FINANCE');
        $add('M_17_WHATS_NEW', "What's new", '#/whats-new', $p('M_17_20_SYSTEM_HEALTH_JOBS'), 'all');
    }

    public function down(): void {}
};
