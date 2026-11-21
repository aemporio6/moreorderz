<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-C-10 / R-C-43: role flags in the roles master instead of config – is_ec (shown first as pills on the External Contact quick form, in `sort`
 * order) and is_paid (opens Payment Terms → one Job history row). Filled from the old config lists ec_roles (12 names, same order) and
 * ec_paid_roles (4 names), names matched upper-case.
 */
return new class extends Migration {
    public const EC = ['VENDOR', 'MANUFACTURER', 'DISTRIBUTER', 'VIRTUAL ASSISTANT', 'GRAPHICS DESIGNER', 'DIGITAL MARKETER', 'CONTRACTOR', 'DRIVER', '3PL', 'INSPECTION', 'LOGISTICS CORDINATOR', 'SALES AGENT'];
    public const PAID = ['VIRTUAL ASSISTANT', 'GRAPHICS DESIGNER', 'DIGITAL MARKETER', 'CONTRACTOR'];

    public function up(): void
    {
        foreach (['is_ec' => 'TINYINT NOT NULL DEFAULT 0', 'is_paid' => 'TINYINT NOT NULL DEFAULT 0', 'sort' => 'INT NOT NULL DEFAULT 999'] as $c => $def)
            if (! Schema::hasColumn('cat_account_types', $c)) DB::statement("ALTER TABLE cat_account_types ADD COLUMN `$c` $def");
        foreach (DB::table('cat_account_types')->get(['id', 'account_type_name']) as $r) {
            $n = strtoupper(trim((string) $r->account_type_name)); $i = array_search($n, self::EC, true);
            DB::table('cat_account_types')->where('id', $r->id)->update(['is_ec' => $i !== false ? 1 : 0, 'is_paid' => in_array($n, self::PAID, true) ? 1 : 0, 'sort' => $i !== false ? $i + 1 : 999]);
        }
    }
    public function down(): void
    {
        foreach (['is_ec', 'is_paid', 'sort'] as $c) if (Schema::hasColumn('cat_account_types', $c)) DB::statement("ALTER TABLE cat_account_types DROP COLUMN `$c`");
    }
};
