<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** VA share (R-C-40): a batch imported with no valid job-history row gets 0 % and the warning chip "No VA share set". */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_sale_imports', 'va_share_missing')) DB::statement('ALTER TABLE t_sale_imports ADD va_share_missing TINYINT(1) NOT NULL DEFAULT 0');
    }
    public function down(): void {}
};
