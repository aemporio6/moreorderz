<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-D-70: product type / trend – name and code required, the trend form says "Trend name"; the code is upper case and unique in the company (checked on save). */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([['cat_product_type', 'product_type_name', 'Product type name', 'At most 50 letters.'], ['cat_product_type', 'iso_product_type', 'Code', 'Short code, upper case, unique in the company (e.g. KIT).'],
                  ['cat_product_trends', 'product_trend_name', 'Trend name', 'At most 50 letters.'], ['cat_product_trends', 'iso_product_trend', 'Code', 'Short code, upper case, unique in the company.']] as [$f, $n, $l, $h])
            DB::table('sys_registry')->where('form_key', $f)->where('field_name', $n)->update(['label' => $l, 'help' => $h, 'required' => 1]);
    }

    public function down(): void {}
};
