<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-D-5 variation colour and size lists, in the old order; each colour has a hex for the swatch (owner to confirm the values). */
return new class extends Migration
{
    private const COLOURS = ['Red' => '#d62828', 'Black' => '#111111', 'Green' => '#2a9d55', 'Yellow' => '#f5c518', 'Purple' => '#7b2cbf', 'Blue' => '#1f6feb', 'Brown' => '#7f4f24', 'Gray' => '#8a8a8a', 'White' => '#ffffff', 'Golden' => '#d4a017',
        'Silver' => '#c0c0c0', 'Rose Gold' => '#b76e79', 'Maltaee' => '#f28c28', 'Aanghori' => '#9acd32', 'Tarboozu' => '#e8505b', 'Jaamni' => '#5b2a86', 'Whisky' => '#b5651d', 'Gold' => '#ffd700', 'Copper' => '#b87333', 'Orange' => '#ff8c00',
        'Pink' => '#ff69b4', 'Mixed' => '#9e9e9e', 'Grey' => '#808080', 'Violet' => '#8f00ff', 'Aqua Green' => '#12e193', 'Apple Rose' => '#e2725b', 'Peach' => '#ffcba4', 'Peach Purple' => '#c38aa9', 'Wine Red' => '#722f37', 'Magnetic Black' => '#1b1b2f'];
    private const SIZES = ['XS' => 'Extra Small', 'S' => 'Small', 'M' => 'Medium', 'L' => 'Large', 'XL' => 'Extra Large', 'XXL' => 'XXL', 'XXXL' => 'XXXL', '10x10' => '10x10', '8x8' => '8x8', '5x5' => '5x5', '3x3' => '3x3'];

    public function up(): void
    {
        if (! Schema::hasColumn('sys_field_options', 'hex')) DB::statement('ALTER TABLE sys_field_options ADD COLUMN hex VARCHAR(9) NULL');
        $n = 0; foreach (self::COLOURS as $v => $hex) { $n += 10; if (! DB::table('sys_field_options')->where('list_code', 'VARIANT_COLOUR')->where('value', $v)->exists()) DB::table('sys_field_options')->insert(['list_code' => 'VARIANT_COLOUR', 'value' => $v, 'label' => $v, 'sort' => $n, 'f_active' => 1, 'hex' => $hex]); }
        $n = 0; foreach (self::SIZES as $v => $l) { $n += 10; if (! DB::table('sys_field_options')->where('list_code', 'VARIANT_SIZE')->where('value', $v)->exists()) DB::table('sys_field_options')->insert(['list_code' => 'VARIANT_SIZE', 'value' => $v, 'label' => $l, 'sort' => $n, 'f_active' => 1]); }
    }

    public function down(): void { DB::table('sys_field_options')->whereIn('list_code', ['VARIANT_COLOUR', 'VARIANT_SIZE'])->delete(); if (Schema::hasColumn('sys_field_options', 'hex')) DB::statement('ALTER TABLE sys_field_options DROP COLUMN hex'); }
};
