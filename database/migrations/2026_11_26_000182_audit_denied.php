<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-121: a refused page (403) is written to the audit log – the action list gets DENIED. */
return new class extends Migration {
    public function up(): void
    {
        $t = DB::select("SHOW COLUMNS FROM sys_audit_log LIKE 'action'")[0]->Type;
        if (! str_contains($t, "'DENIED'")) DB::statement('ALTER TABLE sys_audit_log MODIFY action ' . substr($t, 0, -1) . ",'DENIED') NOT NULL");
    }

    public function down(): void {}
};
