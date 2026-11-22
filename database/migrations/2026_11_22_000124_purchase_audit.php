<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-A_SALES-61 / 63 / 64: the audit profile of a supplier (NONE / AMAZON / GD) and the note-parse error of an eBay audit row. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('t_vendors', 'audit_profile')) DB::statement("ALTER TABLE t_vendors ADD COLUMN audit_profile VARCHAR(10) NOT NULL DEFAULT 'NONE'");
        if (! Schema::hasColumn('ebay_audit', 'parse_error')) DB::statement('ALTER TABLE ebay_audit ADD COLUMN parse_error VARCHAR(250) NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_vendors', 'audit_profile')) DB::statement('ALTER TABLE t_vendors DROP COLUMN audit_profile');
        if (Schema::hasColumn('ebay_audit', 'parse_error')) DB::statement('ALTER TABLE ebay_audit DROP COLUMN parse_error');
    }
};
