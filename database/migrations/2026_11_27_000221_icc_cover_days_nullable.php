<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** MR-1324: a product without sales never runs out – its cover days are empty, not an error (the ICC of a new product failed on this). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE icc_results MODIFY cover_days INT NULL');
    }

    public function down(): void {}
};
