<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** MR-0086 the one due-dates engine also covers trademark expiry and samples still to arrive (ID expiry, subscriptions, VAT, PO payments were there). */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['source_table' => 'cat_brands', 'date_column' => 'tm_expiry_date', 'title_template' => 'Trademark of {brand_name} expires', 'owner_rule' => 'role:OWNER', 'remind_days' => 90, 'done_rule' => 'date moved forward', 'where_rule' => 'f_active=1', 'code' => 'TM_EXPIRY', 'name' => 'Trademark expiry', 'task_code' => 'ACC_DUE_ITEM', 'chip' => 'Trademark'],
            ['source_table' => 't_src_samples', 'date_column' => 'sample_date', 'title_template' => 'Sample {sample_code} not received yet', 'owner_rule' => 'role:MANAGER', 'remind_days' => 7, 'done_rule' => 'received date entered', 'where_rule' => 'f_active=1 AND received_date IS NULL', 'code' => 'SAMPLE_DUE', 'name' => 'Sample to arrive', 'task_code' => 'ACC_DUE_ITEM', 'chip' => 'Sample'],
        ];
        foreach ($rows as $r) if (! DB::table('sys_due_sources')->where('code', $r['code'])->exists()) DB::table('sys_due_sources')->insert($r + ['f_active' => 1]);
    }

    public function down(): void {}
};
