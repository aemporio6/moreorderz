<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pack verification, weight, box and packing materials (R-H-29): the product pack spec (packed size, weight, box), a carrier preset that needs the weight,
 * scanned items per parcel line, the settings WH_SCAN_CONFIRM_PACK / WH_PACKING_STOCK. The same fields are in database/meta/field_registry.csv.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_product_vendors', 'fk_id_pack_box')) DB::statement('ALTER TABLE t_product_vendors ADD fk_id_pack_box INT NULL, ADD pack_weight_g INT NULL, ADD pack_l_mm SMALLINT NULL, ADD pack_w_mm SMALLINT NULL, ADD pack_h_mm SMALLINT NULL');
        if (! Schema::hasColumn('t_carrier_pkg_svc', 'needs_weight')) DB::statement('ALTER TABLE t_carrier_pkg_svc ADD needs_weight TINYINT(1) NOT NULL DEFAULT 0');
        if (! Schema::hasColumn('wh_parcel_lines', 'qty_scanned')) DB::statement('ALTER TABLE wh_parcel_lines ADD qty_scanned INT NULL');
        if (! Schema::hasColumn('wh_parcels', 'box_suggested')) DB::statement('ALTER TABLE wh_parcels ADD box_suggested INT NULL, ADD packing_cost DECIMAL(14,2) NULL');
        $t = DB::selectOne("SHOW COLUMNS FROM t_stock_movements LIKE 'movement_type'")->Type;
        if (! str_contains($t, 'packing_use')) DB::statement('ALTER TABLE t_stock_movements MODIFY movement_type ' . str_replace(")", ",'packing_use')", $t) . ' NOT NULL');
        foreach (DB::table('t_enterprise')->pluck('id') as $ent) foreach (['WH_SCAN_CONFIRM_PACK' => 'Y', 'WH_PACKING_STOCK' => 'N'] as $k => $v)
            if (! DB::table('sys_settings')->where('fk_id_ent', $ent)->where('setting_key', $k)->exists()) DB::table('sys_settings')->insert(['fk_id_ent' => $ent, 'setting_key' => $k, 'setting_value' => $v]);
        $base = ['form_title' => 'Product', 'section' => 'Shipping', 'required' => 0, 'in_quick' => 0, 'visible_to' => 'all', 'readonly' => 0, 'table_name' => 't_product_vendors', 'is_master' => 0, 'secret' => 0];
        $f = [
            ['fk_id_pack_box', 'Pack box', 'select', 'table:wh_box_sizes', 'The box this product ships in on its own (packaging spec). Suggested at Pack for single-SKU parcels.'],
            ['pack_weight_g', 'Packed weight (g)', 'number', null, 'Weight of one unit ready to ship, in grams. Used for the box and weight suggestion at Pack.'],
            ['pack_l_mm', 'Packed length (mm)', 'number', null, 'Packed size of one unit, mm. Used to find the smallest box that fits.'],
            ['pack_w_mm', 'Packed width (mm)', 'number', null, 'Packed size of one unit, mm.'],
            ['pack_h_mm', 'Packed height (mm)', 'number', null, 'Packed size of one unit, mm.'],
        ];
        foreach (['product' => ['library', 6], 't_product_vendor' => ['module', 5]] as $form => [$kind, $sec])
            foreach ($f as $i => [$name, $label, $type, $src, $help])
                if (! DB::table('sys_registry')->where('form_key', $form)->where('field_name', $name)->exists())
                    DB::table('sys_registry')->insert(['form_kind' => $kind, 'section_no' => $sec] + $base + ['form_key' => $form, 'field_no' => 10 + $i, 'field_name' => $name, 'label' => $label, 'type' => $type, 'source' => $src, 'help' => $help, 'column_name' => $name]);
        if (! DB::table('sys_event_types')->where('event_code', 'PACK_SHORT')->exists()) DB::table('sys_event_types')->insert(['event_code' => 'PACK_SHORT', 'area' => 'Warehouse', 'description' => 'Item missing at pack – back to picking', 'f_active' => 1]);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
