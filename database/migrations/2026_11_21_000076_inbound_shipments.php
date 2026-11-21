<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Inbound shipment prep (R-H-49): lots kept on a pick line, task rule WH_INBOUND_PICK, menu "Inbound shipments", the label templates and routes of the new print kinds. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('wh_pick_task_lines', 'lots_json')) DB::statement("ALTER TABLE wh_pick_task_lines ADD COLUMN lots_json TEXT NULL COMMENT 'lots picked on this line {lot: qty} – a two-step transfer moves the same lots'");
        if (! DB::table('sys_rules')->where('rule_code', 'WH_INBOUND_PICK')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'WH_INBOUND_PICK', 'name' => 'Inbound shipment to pick into cartons', 'module' => 'Warehouse', 'trigger_event' => null, 'level' => 'NORMAL', 'assign_to' => 'role:WAREHOUSE', 'due_days' => 1,
                'handler' => 'InboundShipments::reserve', 'f_active' => 1, 'priority' => 'NORMAL', 'category_id' => 6, 'owner_rule' => 'role:WAREHOUSE', 'dedupe_key' => 'WH_INBOUND_PICK:<fba_shipment>', 'auto_close' => 'shipment shipped', 'fix_link' => '#/inbound/<id>']);
        if (! DB::table('sidebar_modules')->where('module_key', 'M_4_18_INBOUND')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_4_18_INBOUND', 'module_name' => 'Inbound shipments (FBA / 3PL)', 'url' => '#/inbound', 'parent_id' => 90400, 'sort_order' => 18, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'WAREHOUSE, STOCK, MANAGER', 'screen_no' => '4.18']);
        if (DB::table('print_adapters')->exists()) \App\Services\PrintService::seed();
    }
    public function down(): void
    {
        DB::table('sidebar_modules')->where('module_key', 'M_4_18_INBOUND')->delete(); DB::table('sys_rules')->where('rule_code', 'WH_INBOUND_PICK')->delete();
        DB::table('label_templates')->whereIn('code', ['FBA_CARTON_FLOW', 'FBA_ITEM_FLOW', 'FBA_PACKLIST_A4'])->delete(); DB::table('print_routes')->whereIn('job_kind', ['FBA_CARTON', 'FBA_ITEM', 'FBA_PACKLIST'])->delete();
        if (Schema::hasColumn('wh_pick_task_lines', 'lots_json')) DB::statement('ALTER TABLE wh_pick_task_lines DROP COLUMN lots_json');
    }
};
