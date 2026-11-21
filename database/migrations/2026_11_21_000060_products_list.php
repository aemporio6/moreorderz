<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Products list (R-D-35): the reference list PRODUCT_ROW_STATUS (text colours, first match wins, inactive before alone) and the menu entry. */
return new class extends Migration {
    public function up(): void
    {
        $i = 0;
        foreach (\App\Services\ProductsList::ROW_STATUS as $v => [$label, $colour]) {
            $i++;
            if (! DB::table('sys_field_options')->where('list_code', 'PRODUCT_ROW_STATUS')->where('value', $v)->exists())
                DB::table('sys_field_options')->insert(['list_code' => 'PRODUCT_ROW_STATUS', 'value' => $v, 'label' => "$label ($colour)", 'sort' => $i * 10, 'f_active' => 1]);
        }
        DB::table('sidebar_modules')->where('module_key', 'M_5_1_PRODUCTS')->update(['url' => '#/products']);
    }
    public function down(): void {}
};
