<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-H-17: cancel after pick → a PUTBACK task (DISPATCH → home bin); R-H-35: carrier took fewer → un-scan event. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('sys_rules')->where('rule_code', 'WH_PUTBACK')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'WH_PUTBACK', 'name' => 'Put picked units back', 'module' => 'Warehouse', 'level' => 'NORMAL', 'assign_to' => 'role:STOCK', 'due_days' => 0, 'handler' => 'Rules_wh::putback', 'f_active' => 1,
                'priority' => 'NORMAL', 'category_id' => 6, 'owner_rule' => 'role:STOCK', 'dedupe_key' => 'WH_PUTBACK:<sale line>', 'auto_close' => 'units put back', 'fix_link' => '#/orders']);
        foreach (['PUTBACK_DONE' => 'Picked units put back after a cancel', 'HANDOVER_UNSCANNED' => 'Parcel un-scanned from a closed handover'] as $code => $name)
            if (! DB::table('sys_event_types')->where('event_code', $code)->exists()) DB::table('sys_event_types')->insert(['event_code' => $code, 'area' => 'Warehouse', 'description' => $name, 'f_active' => 1]);
    }

    public function down(): void { DB::table('sys_rules')->where('rule_code', 'WH_PUTBACK')->delete(); DB::table('sys_event_types')->whereIn('event_code', ['PUTBACK_DONE', 'HANDOVER_UNSCANNED'])->delete(); }
};
