<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner 27-11 02:31: packaging in one place on the product – item, retail pack, master carton – with clear units; the item size as numbers. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('t_product_vendors', 'item_l_mm')) Schema::table('t_product_vendors', function (Blueprint $t) { $t->decimal('item_l_mm', 10, 1)->nullable(); $t->decimal('item_w_mm', 10, 1)->nullable(); $t->decimal('item_h_mm', 10, 1)->nullable(); });
        $ship = DB::table('sys_registry')->where('form_key', 't_product_vendor')->where('section', 'Shipping')->first(); if (! $ship) return;
        foreach ([
            'weight' => ['Item weight (kg)', 'The item alone, without its packaging, in kg.', 101],
            'item_l_mm' => ['Item length (mm)', 'The item alone, longest side, mm.', 102],
            'item_w_mm' => ['Item width (mm)', null, 103],
            'item_h_mm' => ['Item height (mm)', null, 104],
            'fk_id_pack_box' => ['Pack box', 'The box / bag the item is sold in (retail pack).', 110],
            'pack_l_mm' => ['Packed length (mm)', 'Packed size of one unit, mm. Used for the best carrier box, the courier volumetric weight and the listing.', 112],
            'pack_w_mm' => ['Packed width (mm)', null, 113],
            'pack_h_mm' => ['Packed height (mm)', null, 114],
            'pack_weight_g' => ['Packed weight (g)', 'One unit in its pack, grams.', 115],
            'units_per_carton' => ['Units per carton', 'Packs in one master carton.', 120],
            'carton_l' => ['Carton length (cm)', 'Master carton outside size, cm.', 121],
            'carton_w' => ['Carton width (cm)', null, 122],
            'carton_h' => ['Carton height (cm)', null, 123],
            'carton_gross_weight' => ['Carton gross weight (kg)', 'Weighed full carton, kg. Empty = worked out from the pack weight (+5 % + 0.6 kg).', 124],
            'carton_cbm' => ['Carton CBM', 'Worked out by itself from the carton size (L × W × H ÷ 1,000,000).', 125],
            'material' => ['Material', null, 126]
        ] as $f => [$label, $help, $no]) {
            $set = ['label' => $label, 'section' => $ship->section, 'section_no' => $ship->section_no, 'field_no' => $no] + ($help ? ['help' => $help] : []) + ($f === 'carton_cbm' ? ['readonly' => 1] : []);
            if (DB::table('sys_registry')->where('form_key', 't_product_vendor')->where('field_name', $f)->exists()) DB::table('sys_registry')->where('form_key', 't_product_vendor')->where('field_name', $f)->update($set);
            else DB::table('sys_registry')->insert($set + ['form_key' => 't_product_vendor', 'form_title' => $ship->form_title, 'form_kind' => $ship->form_kind, 'field_name' => $f, 'type' => 'number', 'source' => null, 'required' => 0, 'in_quick' => 0,
                'visible_to' => 'all', 'readonly' => 0, 'table_name' => 't_product_vendors', 'column_name' => $f, 'is_master' => 0, 'secret' => 0]);
        }
    }

    public function down(): void
    {
        DB::table('sys_registry')->where('form_key', 't_product_vendor')->whereIn('field_name', ['item_l_mm', 'item_w_mm', 'item_h_mm'])->delete();
        if (Schema::hasColumn('t_product_vendors', 'item_l_mm')) Schema::table('t_product_vendors', fn (Blueprint $t) => $t->dropColumn(['item_l_mm', 'item_w_mm', 'item_h_mm']));
    }
};
