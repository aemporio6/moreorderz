<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Step 7: sale lines brought over from the old tool are "history" – kept and searchable, with no effect on stock, the books or the profit figures. */
return new class extends Migration
{
    public function up(): void { DB::statement("ALTER TABLE t_sales MODIFY sale_source ENUM('active','ds','unmatched','history') NOT NULL DEFAULT 'active'"); }
    public function down(): void {}
};
