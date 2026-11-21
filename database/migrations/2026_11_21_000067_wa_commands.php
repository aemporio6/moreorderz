<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** WhatsApp inbound commands (R-G_CORE-52) and the one task state table (R-G_CORE-1/5): wa_inbound gets action, task, numbered-question memory and the statuses ACTED / RECEIVED. */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE wa_inbound MODIFY status ENUM('NEW','HANDLED','IGNORED','ACTED','RECEIVED') NOT NULL DEFAULT 'NEW'");
        Schema::table('wa_inbound', function ($t) {
            if (! Schema::hasColumn('wa_inbound', 'action')) $t->string('action', 30)->nullable()->after('status');
            if (! Schema::hasColumn('wa_inbound', 'fk_id_task')) $t->integer('fk_id_task')->nullable()->after('action');
            if (! Schema::hasColumn('wa_inbound', 'pending_json')) $t->text('pending_json')->nullable()->after('note');
        });
        DB::table('wa_inbound')->where('status', 'HANDLED')->update(['status' => 'ACTED']);
    }
    public function down(): void {}
};
