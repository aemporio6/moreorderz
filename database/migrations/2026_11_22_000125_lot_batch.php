<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-B_SOURCING-10 the batch of a lot (old entry "Batch (auto if blank)" → HIST-<cycle>-<n>). */
return new class extends Migration
{
    public function up(): void { if (! Schema::hasColumn('fin_lots', 'batch_no')) DB::statement('ALTER TABLE fin_lots ADD COLUMN batch_no VARCHAR(40) NULL'); }
    public function down(): void { if (Schema::hasColumn('fin_lots', 'batch_no')) DB::statement('ALTER TABLE fin_lots DROP COLUMN batch_no'); }
};
