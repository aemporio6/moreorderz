<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Carrier label PDF import (R-A_SALES-85): one row per label page – what was read for matching (never a name or an address line) and where it went. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS mo_label_pages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fk_id_ent INT NOT NULL, fk_id_batch INT NOT NULL, fk_id_branch INT NOT NULL, fk_id_carrier INT NOT NULL, page_no SMALLINT NOT NULL,
            fk_id_document INT NULL, tracking_no VARCHAR(60) NULL, service_text VARCHAR(150) NULL, postcode_out VARCHAR(5) NULL, order_ref VARCHAR(60) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'SET_ASIDE', problem VARCHAR(255) NULL, candidates_json TEXT NULL, fk_id_sale BIGINT NULL, matched_by VARCHAR(12) NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            KEY ix_label_batch (fk_id_batch), KEY ix_label_sale (fk_id_sale), KEY ix_label_track (tracking_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(): void {}
};
