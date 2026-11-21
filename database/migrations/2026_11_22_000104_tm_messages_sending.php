<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-46: claim before send – status SENDING between QUEUED and SENT. */
return new class extends Migration {
    public function up(): void
    {
        // R-G_CORE-53 approval status of a WhatsApp template = a central, fixed list (the provider decides it)
        if (! DB::table('sys_field_lists')->where('list_code', 'WA_APPROVAL')->exists()) DB::table('sys_field_lists')->insert(['list_code' => 'WA_APPROVAL', 'title' => 'WhatsApp template approval', 'f_fixed' => 1, 'note' => 'Set by Meta / 360dialog – the tool only records it.', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['PENDING' => 'Pending', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected'] as $i => $l) if (! DB::table('sys_field_options')->where('list_code', 'WA_APPROVAL')->where('value', $i)->exists())
            DB::table('sys_field_options')->insert(['list_code' => 'WA_APPROVAL', 'value' => $i, 'label' => $l, 'sort' => array_search($i, ['PENDING', 'APPROVED', 'REJECTED']) * 10 + 10, 'f_active' => 1]);
        DB::table('sys_registry')->where('form_key', 'wa_template')->where('field_name', 'approval_status')->update(['source' => 'list:WA_APPROVAL', 'options_full' => null]);
        \App\Services\Registry::forget();
        DB::statement("ALTER TABLE tm_messages MODIFY status ENUM('QUEUED','SENDING','SENT','DELIVERED','READ','FAILED','RECEIVED','IGNORED','ACTED') NOT NULL DEFAULT 'QUEUED'");
    }

    public function down(): void {}
};
