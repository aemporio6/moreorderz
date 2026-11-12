<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One GRN line can make several lots of the same SKU (one per owner, R-B_SOURCING-61) and one lot can be split more than once,
 * so (source, source_id, sku) cannot be unique. A GRN is protected from posting twice by its own status instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $has = DB::select("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'fin_lots' AND index_name = 'ux_lot_src' LIMIT 1");
        if ($has) DB::statement('ALTER TABLE fin_lots DROP INDEX ux_lot_src, ADD INDEX ix_lot_src (source, source_id, sku)');
    }

    public function down(): void
    {
    }
};
