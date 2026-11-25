<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-A_SALES-56: the layout of a supplier's BUY file is kept on the supplier (GENERIC / ALIEXPRESS).
 *  Fix: bulk_jobs.status gets PARTIAL – an import undo that keeps used rows (cycles, old entries) wrote that value and the column refused it. */
return new class extends Migration {
    public function up(): void { if (! Schema::hasColumn('t_vendors', 'buy_layout')) DB::statement("ALTER TABLE t_vendors ADD buy_layout VARCHAR(12) NULL");
        DB::statement("ALTER TABLE bulk_jobs MODIFY status ENUM('PREVIEW','APPLIED','UNDONE','FAILED','PARTIAL') NOT NULL DEFAULT 'PREVIEW'"); }
    public function down(): void {}
};
