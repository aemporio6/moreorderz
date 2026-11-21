<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Cases on a sale (R-A_SALES-71): the thread of a case, who resolved it, the general type REM-GEN and the task rule SALE_QUERY. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mo_sale_case_notes')) Schema::create('mo_sale_case_notes', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_case'); $t->string('kind', 12); $t->string('note', 1000)->default(''); $t->unsignedBigInteger('user_id')->nullable(); $t->string('user_name', 150)->default(''); $t->dateTime('created_at'); $t->index('fk_id_case');
        });
        if (! Schema::hasColumn('t_sale_order_reminders', 'resolved_at')) Schema::table('t_sale_order_reminders', function (Blueprint $t) { $t->dateTime('resolved_at')->nullable(); $t->unsignedBigInteger('resolved_by')->nullable(); $t->unsignedInteger('fk_id_ent')->default(0); });
        DB::statement('ALTER TABLE t_sale_order_reminders MODIFY admin_remarks VARCHAR(500) NULL');
        if ($ref = DB::table('cat_references')->where('ref_code', 'REMINDER_TYPE')->value('id')) {
            if (! DB::table('reference_items')->where('fk_reference_id', $ref)->where('item_code', 'REM-GEN')->exists()) DB::table('reference_items')->insert(['fk_reference_id' => $ref, 'reference_name' => 'General', 'item_code' => 'REM-GEN', 'fk_id_ent' => 0, 'sort_no' => 0, 'f_active' => 1, 'f_pending' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! DB::table('sys_rules')->where('rule_code', 'SALE_QUERY')->exists() && ($like = DB::table('sys_rules')->where('rule_code', 'SHOP_REMINDER')->first())) {
            $row = (array) $like; unset($row['id']);
            DB::table('sys_rules')->insert(array_merge($row, ['rule_code' => 'SALE_QUERY', 'name' => 'Case on a sale waits for a reply', 'module' => 'Sales', 'handler' => 'SaleCases::raise', 'assign_to' => 'role:TL', 'level' => 'NORMAL', 'priority' => 'HIGH', 'due_days' => 1, 'dedupe_key' => 'SALE_QUERY:<case>', 'auto_close' => 'the case is resolved', 'fix_link' => '#/sale-cases']));
        }
    }
    public function down(): void { Schema::dropIfExists('mo_sale_case_notes'); }
};
