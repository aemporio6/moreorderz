<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Field-by-field check of the old-data import: per table and column, how many old rows had a value, how many new rows have one, and how many differ. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS mo_import_fields (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_code VARCHAR(30) NOT NULL, old_table VARCHAR(64) NOT NULL, column_name VARCHAR(64) NOT NULL,
            rows_both INT NOT NULL DEFAULT 0, old_filled INT NOT NULL DEFAULT 0, new_filled INT NOT NULL DEFAULT 0, differ INT NOT NULL DEFAULT 0, status VARCHAR(12) NOT NULL, note VARCHAR(255) NULL, created_at DATETIME NULL,
            KEY ix_if_run (run_code), KEY ix_if_table (old_table)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(): void {}
};
