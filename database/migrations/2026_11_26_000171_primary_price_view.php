<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-D-24: read view v_product_primary_price – for every product the raw / sale / list price, fee, carrier preset and packing of the company's
 * primary marketplace (setting PRIMARY_MARKETPLACE per company, else the global value, else the first active marketplace by id).
 */
return new class extends Migration {
    public function up(): void
    {
        /* R-D-43 a voided purchase keeps its lot as a record */
        foreach (['lot_status' => "VARCHAR(8) NOT NULL DEFAULT 'ACTIVE'", 'void_reason' => 'VARCHAR(255) NULL', 'voided_at' => 'DATETIME NULL', 'voided_by' => 'INT NULL'] as $c => $t)
            if (! \Illuminate\Support\Facades\Schema::hasColumn('fin_lots', $c)) DB::statement("ALTER TABLE fin_lots ADD $c $t");
        DB::statement('DROP VIEW IF EXISTS v_product_primary_price');
        DB::statement("CREATE VIEW v_product_primary_price AS
            SELECT p.id AS fk_id_product, p.fk_id_ent, pm.mp AS fk_id_marketplace,
                   pr.raw_price, pr.sale_price, pr.list_price, pr.marketplace_fee AS ebay_fee, sh.fk_id_car_pkg_svc, sh.fk_id_packing
            FROM t_product_vendors p
            JOIN (SELECT e.id AS ent,
                         COALESCE((SELECT CAST(s.setting_value AS UNSIGNED) FROM sys_settings s WHERE s.setting_key = 'PRIMARY_MARKETPLACE' AND s.fk_id_ent = e.id AND s.setting_value REGEXP '^[0-9]+$' LIMIT 1),
                                  (SELECT CAST(s.setting_value AS UNSIGNED) FROM sys_settings s WHERE s.setting_key = 'PRIMARY_MARKETPLACE' AND (s.fk_id_ent IS NULL OR s.fk_id_ent = 0) AND s.setting_value REGEXP '^[0-9]+$' LIMIT 1),
                                  (SELECT MIN(m.id) FROM cat_marketplaces m WHERE m.f_active = 1)) AS mp
                  FROM t_enterprise e) pm ON pm.ent = p.fk_id_ent
            LEFT JOIN t_product_marketplace_prices pr ON pr.fk_id_product = p.id AND pr.fk_id_marketplace = pm.mp
            LEFT JOIN t_product_marketplace_shipping sh ON sh.fk_id_product = p.id AND sh.fk_id_marketplace = pm.mp");
    }
    public function down(): void { DB::statement('DROP VIEW IF EXISTS v_product_primary_price'); }
};
