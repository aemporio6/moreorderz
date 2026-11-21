<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Carton figures calculated, never typed (R-B_SOURCING-34); HS / ISP codes typed (R-G_CORE-91); a volumetric divisor per carrier. Same change in database/meta/field_registry.csv. */
return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['product', 'hs_code', ['help' => 'Typed: digits only, 6–10 (dots and spaces are removed), for example 3924.90.00 → 39249000. Never a system number.', 'default_rule' => null]],
            ['src_cycle', 'cartons', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['src_cycle', 'carton_kg', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['src_cycle', 'cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['quick_buy', 'cartons', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['quick_buy', 'carton_kg', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['quick_buy', 'cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['old_entry', 'cartons', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['old_entry', 'carton_kg', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['old_entry', 'cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['cat_ip_burgers', 'isp_code', ['auto_correct' => 'upper', 'help' => 'Typed IPBurger ISP code: letters / digits, up to 20. Never a system number.', 'default_rule' => null]],
            ['t_product_vendor', 'hs_code', ['help' => 'Typed: digits only, 6–10 (dots and spaces are removed), for example 3924.90.00 → 39249000. Never a system number.', 'default_rule' => null]],
            ['t_product_vendor', 'carton_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated: carton L × W × H (cm) ÷ 1,000,000, 4 decimals.', 'default_rule' => 'rule:R-G_CORE-91']],
            ['t_src_cartons', 'unit_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'full_cartons', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'loose_units', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'carton_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'volumetric_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'carton_qty', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'carton_net_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'carton_gross_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'actual_carton_gross_weight', ['help' => 'Weighed carton gross (kg). When above 0 it replaces the calculated carton gross.']],
            ['t_src_cartons', 'total_units', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'total_net_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'total_gross_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons', 'total_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v1', 'carton_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v1', 'carton_qty', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v1', 'carton_gross_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v1', 'total_units', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v1', 'total_net_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v1', 'total_gross_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v1', 'total_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'unit_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'full_cartons', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'loose_units', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'carton_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'volumetric_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'carton_qty', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'carton_net_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'carton_gross_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'actual_carton_gross_weight', ['help' => 'Weighed carton gross (kg). When above 0 it replaces the calculated carton gross.']],
            ['t_src_cartons_v2', 'total_units', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'total_net_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'total_gross_weight', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_cartons_v2', 'total_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_historical', 'cartons', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_historical', 'carton_kg', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_historical', 'cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated (R-B_SOURCING-34) – changes when the typed figures change.', 'default_rule' => 'rule:R-B-34']],
            ['t_src_products', 'hs_code', ['help' => 'Typed: digits only, 6–10 (dots and spaces are removed), for example 3924.90.00 → 39249000. Never a system number.', 'default_rule' => null]],
            ['t_src_products', 'carton_cbm', ['type' => 'number', 'readonly' => 1, 'help' => 'System calculated: carton L × W × H (cm) ÷ 1,000,000, 4 decimals.', 'default_rule' => 'rule:R-G_CORE-91']],
        ] as [$f, $n, $set]) DB::table('sys_registry')->where('form_key', $f)->where('field_name', $n)->update($set);
        if (! Schema::hasColumn('t_carriers', 'volumetric_divisor')) DB::statement('ALTER TABLE t_carriers ADD volumetric_divisor DECIMAL(10,2) NULL');
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
