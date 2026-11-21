<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Gen-2 money values kept for the migration compare (R-A_SALES-52): copied as they are, never recomputed. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_sales', 'legacy_profit')) DB::statement("ALTER TABLE t_sales ADD legacy_profit DECIMAL(12,2) NULL, ADD legacy_loss DECIMAL(12,2) NULL, ADD legacy_pur_amount DECIMAL(12,2) NULL,
            ADD legacy_pur_rate DECIMAL(12,2) NULL, ADD legacy_raw DECIMAL(12,2) NULL, ADD legacy_status VARCHAR(20) NULL, ADD f_loss_line TINYINT(1) NOT NULL DEFAULT 0");
    }
    public function down(): void {}
};
