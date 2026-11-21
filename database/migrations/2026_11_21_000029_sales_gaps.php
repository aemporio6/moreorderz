<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Message template for the daily list of sale lines still not matched (one per shop VA). */
return new class extends Migration {
    public function up(): void
    {
        DB::table('wa_templates')->updateOrInsert(['code' => 'NOT_MATCHED_DIGEST'], ['title' => 'Not matched sale lines', 'audience' => 'VA', 'body' => 'Good morning {name}: {count} sale line(s) are still not matched – {shops}. {link}', 'f_active' => 1]);
        // one batch per shop + month + kind: order lines and daily totals of the same month are two batches (R-A_SALES-1)
        $idx = collect(DB::select("SHOW INDEX FROM t_sale_imports WHERE Key_name = 'uq_sale_import_shop_month'"))->pluck('Column_name')->all();
        if ($idx && ! in_array('import_nature', $idx, true)) { DB::statement('ALTER TABLE t_sale_imports DROP INDEX uq_sale_import_shop_month'); DB::statement('ALTER TABLE t_sale_imports ADD UNIQUE KEY uq_sale_import_shop_month (' . implode(', ', $idx) . ', import_nature)'); }
    }
    public function down(): void {}
};
