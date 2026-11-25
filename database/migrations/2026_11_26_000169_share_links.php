<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-B_SOURCING-64 supplier link without login: what the supplier answered is kept on the PO; two rule tasks for the PO owner. */
return new class extends Migration {
    public function up(): void
    {
        foreach (['supplier_confirmed_at' => 'DATETIME NULL', 'supplier_change_note' => 'VARCHAR(500) NULL', 'supplier_ship_date' => 'DATE NULL'] as $c => $t)
            if (! Schema::hasColumn('t_src_po', $c)) DB::statement("ALTER TABLE t_src_po ADD $c $t");
        foreach (['SRC_PO_CHANGE_ASKED' => ['The supplier asks a change on a PO', 'answered or PO accepted'], 'SRC_SHIP_DATE_PROPOSED' => ['The supplier gave a ready / ship date', 'date accepted']] as $code => [$name, $close])
            if (! DB::table('sys_rules')->where('rule_code', $code)->exists())
                DB::table('sys_rules')->insert(['rule_code' => $code, 'name' => $name, 'module' => 'Sourcing', 'level' => 'NORMAL', 'assign_to' => 'role:MANAGER', 'due_days' => 2, 'handler' => 'ShareLinks::act', 'f_active' => 1,
                    'priority' => 'NORMAL', 'owner_rule' => 'record.user,role:MANAGER', 'dedupe_key' => $code . ':<po>', 'auto_close' => $close, 'fix_link' => '#/po/<id>']);
    }
    public function down(): void {}
};
