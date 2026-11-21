<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Company 3PL tariff per marketplace (R-D-23): packing fee and storage fee with "per order" / "per unit", the defaults of new product marketplace rows. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('t_3pl_tariffs')) DB::statement("CREATE TABLE t_3pl_tariffs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fk_id_ent INT NOT NULL, fk_id_marketplace INT NOT NULL,
            packing_fee DECIMAL(10,2) NOT NULL DEFAULT 0, packing_fee_per ENUM('ORDER','UNIT') NOT NULL DEFAULT 'ORDER',
            storage_fee DECIMAL(10,2) NOT NULL DEFAULT 0, storage_fee_per ENUM('ORDER','UNIT') NOT NULL DEFAULT 'UNIT',
            updated_by INT NULL, updated_at DATETIME NULL,
            UNIQUE KEY uq_ent_mp (fk_id_ent, fk_id_marketplace)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (! Schema::hasColumn('t_product_marketplace_shipping', 'fee_source')) DB::statement("ALTER TABLE t_product_marketplace_shipping ADD fee_source VARCHAR(10) NULL COMMENT 'TARIFF = filled from the company 3PL tariff, TYPED = typed'");
    }
    public function down(): void {}
};
