<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-H-59 #6 / R-H-26: wrong scans per picker / packer (wrong bin, wrong item at pick; wrong item at pack). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wh_scan_errors')) DB::statement("CREATE TABLE wh_scan_errors (id BIGINT AUTO_INCREMENT PRIMARY KEY, fk_id_ent INT NOT NULL, user_id INT NOT NULL DEFAULT 0, kind VARCHAR(12) NOT NULL, ref_id BIGINT NULL, expected VARCHAR(100) NULL, scanned VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, KEY ix_user (fk_id_ent, user_id, created_at))");
    }

    public function down(): void { DB::statement('DROP TABLE IF EXISTS wh_scan_errors'); }
};
