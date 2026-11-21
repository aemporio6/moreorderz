<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shop monthly reminders: templates per type that make a reminder + a task on the 1st of each month. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mo_reminder_templates')) Schema::create('mo_reminder_templates', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_ent'); $t->unsignedInteger('fk_id_branch')->default(0); $t->unsignedInteger('fk_id_rem_type'); $t->string('reminder_text', 500); $t->unsignedTinyInteger('due_day')->default(5); $t->tinyInteger('f_active')->default(1); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });
        if (! Schema::hasColumn('t_branch_reminders', 'fk_id_template')) Schema::table('t_branch_reminders', function (Blueprint $t) { $t->unsignedBigInteger('fk_id_template')->nullable(); $t->unsignedBigInteger('resolved_by')->nullable(); $t->dateTime('resolved_at')->nullable(); });
        if (! DB::table('sys_rules')->where('rule_code', 'SHOP_REMINDER')->exists() && ($like = DB::table('sys_rules')->where('rule_code', 'SAL_MONTH_MISSING')->first())) {
            $row = (array) $like; unset($row['id']);
            DB::table('sys_rules')->insert(array_merge($row, ['rule_code' => 'SHOP_REMINDER', 'name' => 'Shop monthly reminder', 'handler' => 'ShopReminders::monthly', 'level' => 'NORMAL', 'priority' => 'NORMAL', 'due_days' => 4, 'dedupe_key' => 'SHOP_REMINDER:<reminder>', 'auto_close' => 'the reminder is resolved', 'fix_link' => '#/shop-reminders']));
        }
    }
    public function down(): void { Schema::dropIfExists('mo_reminder_templates'); }
};
