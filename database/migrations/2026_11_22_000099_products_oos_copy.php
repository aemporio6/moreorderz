<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-D-7 out-of-stock automation: the manual tick is an override (oos_manual) with a reason (oos_reason); listings carry the flag too.
 * R-D-8 duplicate product: the price-change reason "Created" for the opening period of a copy.
 * R-D-10 central (company-owned) inventory: setting PRODUCT_DEFAULT_VENDOR is the company's own supplier (empty = no central products).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_product_vendors', function (Blueprint $t) {
            if (! Schema::hasColumn('t_product_vendors', 'oos_manual')) $t->tinyInteger('oos_manual')->nullable();
            if (! Schema::hasColumn('t_product_vendors', 'oos_reason')) $t->string('oos_reason', 255)->nullable();
        });
        Schema::table('t_product_listings', function (Blueprint $t) { if (! Schema::hasColumn('t_product_listings', 'f_out_of_stock')) $t->tinyInteger('f_out_of_stock')->default(0); });
        foreach ([['PRODUCT_OOS', 'product goes out of stock'], ['PRODUCT_BACK_IN_STOCK', 'product is back in stock (R-D-7)']] as [$c, $d])
            if (! DB::table('sys_event_types')->where('event_code', $c)->exists()) DB::table('sys_event_types')->insert(['event_code' => $c, 'area' => 'Masters', 'description' => $d, 'f_active' => 1]);
        if (! DB::table('cat_price_change_reasons')->where('reason_name', 'Created')->exists()) DB::table('cat_price_change_reasons')->insert(['reason_name' => 'Created', 'f_active' => 1, 'created_at' => now()]);
        foreach ([['product', 'f_out_of_stock'], ['t_product_vendor', 'f_out_of_stock']] as [$form, $after]) {
            $f = DB::table('sys_registry')->where('form_key', $form)->where('field_name', $after)->first();
            if ($f && ! DB::table('sys_registry')->where('form_key', $form)->where('field_name', 'oos_reason')->exists()) {
                $row = (array) $f; unset($row['id']);
                DB::table('sys_registry')->insert(array_merge($row, ['field_no' => (int) DB::table('sys_registry')->where('form_key', $form)->where('section', $f->section)->max('field_no') + 1, 'field_name' => 'oos_reason', 'label' => 'Reason (Out of stock changed by hand)', 'type' => 'text', 'source' => null,
                    'required' => 0, 'in_quick' => 0, 'column_name' => 'oos_reason', 'help' => 'Needed when you tick or untick "Out of stock" by hand. The tick overrides the automatic flag; unticked, the flag follows the stock again.', 'default_rule' => null, 'add_form' => null]));
            }
        }
    }

    public function down(): void
    {
        DB::table('sys_registry')->where('field_name', 'oos_reason')->delete();
        Schema::table('t_product_listings', fn (Blueprint $t) => $t->dropColumn('f_out_of_stock'));
        Schema::table('t_product_vendors', fn (Blueprint $t) => $t->dropColumn(['oos_manual', 'oos_reason']));
    }
};
