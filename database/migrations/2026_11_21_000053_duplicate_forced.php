<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Duplicate guard supplier + order reference (R-B_SOURCING-7): "Save anyway" is kept in history as DUPLICATE_FORCED with its reason. */
return new class extends Migration {
    public function up(): void
    {
        $t = DB::selectOne("SHOW COLUMNS FROM sys_audit_log LIKE 'action'")->Type;
        if (! str_contains($t, 'DUPLICATE_FORCED')) DB::statement('ALTER TABLE sys_audit_log MODIFY action ' . str_replace(")", ",'DUPLICATE_FORCED')", $t) . ' NOT NULL');
    }
    public function down(): void {}
};
