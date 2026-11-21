<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-G_CORE-24 / 25: any event of the catalogue can fire a template (trigger varchar, MANUAL default; GRN_SAVED → GRN_POSTED, v1.7 B10),
 * owner_role open for WAREHOUSE / STOCK, the trigger list gets MANUAL, and the go-live baseline in tm_trigger_seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE task_templates MODIFY trigger_event VARCHAR(40) NOT NULL DEFAULT 'MANUAL'");
        DB::table('task_templates')->where('trigger_event', 'GRN_SAVED')->update(['trigger_event' => 'GRN_POSTED']);
        DB::table('task_templates')->where('trigger_event', '')->update(['trigger_event' => 'MANUAL']);
        DB::statement("ALTER TABLE task_template_items MODIFY owner_role VARCHAR(20) NOT NULL DEFAULT 'VA'");
        DB::statement('ALTER TABLE task_template_items MODIFY category_id INT NULL');      // a category is optional: the role decides the owner
        if (! Schema::hasColumn('tm_trigger_seen', 'template_tasks')) DB::statement('ALTER TABLE tm_trigger_seen ADD template_tasks INT NOT NULL DEFAULT 0 AFTER result');
        $opt = fn ($list, $v, $l, $s) => DB::table('sys_field_options')->updateOrInsert(['list_code' => $list, 'value' => $v], ['label' => $l, 'sort' => $s, 'f_active' => 1]);
        $opt('EVENT_CODE', 'MANUAL', 'Manual (by hand only)', 0);
        foreach (['WAREHOUSE' => 'Warehouse', 'STOCK' => 'Stock'] as $v => $l) $opt('TEMPLATE_OWNER_ROLE', $v, $l, 50);
        \App\Services\TaskTemplates::baseline();      // (a) every existing record: "existed before Task Management – not fired"
    }

    public function down(): void
    {
        DB::table('sys_field_options')->where('list_code', 'EVENT_CODE')->where('value', 'MANUAL')->delete();
    }
};
