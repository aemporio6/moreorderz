<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-A_SALES-90: the "Reply by" date of an open customer case is a due item (no new task code – the case has its own task). */
return new class extends Migration {
    public function up(): void
    {
        if (DB::table('sys_due_sources')->where('code', 'CASE_REPLY')->exists()) return;
        DB::table('sys_due_sources')->insert(['source_table' => 't_sale_order_reminders', 'date_column' => 'd_reply_by', 'title_template' => 'Reply to the case of order {ebay_order_no}', 'owner_rule' => 'record.fk_id_t_users',
            'remind_days' => 1, 'done_rule' => 'case resolved or date moved', 'f_active' => 1, 'code' => 'CASE_REPLY', 'name' => 'Customer case – reply by', 'task_code' => null, 'where_rule' => "e_status = 'PENDING'", 'chip' => 'Case']);
    }
    public function down(): void {}
};
