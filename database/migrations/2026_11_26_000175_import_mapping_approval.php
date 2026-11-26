<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-D-54 named import mappings carry a flag "approved / shared"; formats saved before this are approved. */
return new class extends Migration {
    public function up(): void
    {
        foreach (['f_approved' => 'TINYINT NOT NULL DEFAULT 1', 'approved_by' => 'INT NULL', 'approved_at' => 'DATETIME NULL'] as $c => $t)
            if (! Schema::hasColumn('csv_generator_history', $c)) DB::statement("ALTER TABLE csv_generator_history ADD $c $t");
    }

    public function down(): void {}
};
